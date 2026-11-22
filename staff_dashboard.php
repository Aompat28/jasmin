<?php



session_start();



require_once 'includes/db_connect.php';







// ตรวจสอบสิทธิ์ ต้องเป็นพนักงาน (ช่าง/แม่บ้าน) เท่านั้น



if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Staff', 'Technician', 'Cleaner'])) {



    header("Location: login.php");



    exit();



}







$staffId = $_SESSION['user_id'];
$staffName = $_SESSION['full_name'];
$staffRole = $_SESSION['role'] ?? 'Technician';
$isMaid = in_array($staffRole, ['Cleaner', 'Housekeeper']);
$roleTitle = $isMaid ? '🧹 แม่บ้าน' : '🔧 ช่างซ่อมบำรุง';
$deptTitle = $isMaid ? 'ฝ่ายดูแลความสะอาด & แม่บ้าน' : 'ฝ่ายช่างซ่อมบำรุง & บริการ';
$pageHeading = $isMaid ? 'จัดการงานทำความสะอาดและบริการ' : 'จัดการงานซ่อมและบริการ';
$subTitle = $isMaid ? 'ระบบงานแม่บ้าน' : 'ระบบงานช่างซ่อม';



$initials = strtoupper(mb_substr($staffName, 0, 2, 'UTF-8'));



$message = "";
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'completed' ? 'completed' : 'active';







// --- 🌟 ระบบส่งงาน (บังคับแนบรูปภาพ) 🌟 ---



if (isset($_POST['complete_job'])) {



    $requestId = $_POST['request_id'];



    



    // ตรวจสอบว่ามีการแนบไฟล์รูปภาพมาหรือไม่



    if (isset($_FILES['completion_image']) && $_FILES['completion_image']['error'] == 0) {



        



        $uploadDir = 'uploads/completions/';



        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }



        



        $fileName = time() . '_done_' . basename($_FILES['completion_image']['name']);



        $targetFilePath = $uploadDir . $fileName;



        



        if (move_uploaded_file($_FILES['completion_image']['tmp_name'], $targetFilePath)) {



            // อัปเดตสถานะเป็น Pending Approval (รอแอดมินอนุมัติ) และบันทึกรูปผลงานพร้อมเวลาเรียลไทม์
            $staffComment = trim($_POST['staff_comment'] ?? '');

            if (!empty($staffComment)) {
                $stmt = $conn->prepare("UPDATE ServiceRequest SET status = 'Pending Approval', completionImage = ?, completedAt = COALESCE(completedAt, NOW()), staffComment = ?, staffCommentedAt = NOW(), adminRemark = NULL WHERE requestId = ?");
                $execRes = $stmt->execute([$targetFilePath, $staffComment, $requestId]);
            } else {
                $stmt = $conn->prepare("UPDATE ServiceRequest SET status = 'Pending Approval', completionImage = ?, completedAt = COALESCE(completedAt, NOW()), adminRemark = NULL WHERE requestId = ?");
                $execRes = $stmt->execute([$targetFilePath, $requestId]);
            }

            if ($execRes) {
                $doneTime = date("d/m/Y H:i:s");
                $activeTab = 'completed';

                $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-sm text-sm font-medium flex items-center justify-between gap-3'>
                    <div class='flex items-center gap-2.5'>
                        <i data-lucide='check-circle' class='w-5 h-5 text-emerald-600 shrink-0'></i>
                        <div>
                            <span class='font-bold text-slate-800'>ส่งงานเรียบร้อยแล้ว! ข้อมูลถูกส่งไปยังแอดมินเพื่อนิติตรวจรับและอนุมัติ</span>
                            <div class='text-xs text-emerald-700 mt-0.5 font-sans'>บันทึกเวลาส่งงานเมื่อ: <span class='font-mono font-bold text-emerald-900'>{$doneTime} น.</span> (สถานะ: รอแอดมินอนุมัติ)</div>
                        </div>
                    </div>
                </div>";



            }



        } else {



            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> อัปโหลดรูปภาพไม่สำเร็จ กรุณาลองใหม่</div>";



        }



    } else {



        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5'></i> ไม่สามารถส่งงานได้! จำเป็นต้องแนบรูปภาพผลงานหลังทำเสร็จทุกครั้ง</div>";



    }



}











// --- 🌟 ระบบบันทึก/รายงานปัญหาเพิ่มเติมจากช่าง (AJAX Endpoint) 🌟 ---
if (isset($_POST['action']) && $_POST['action'] === 'save_staff_comment') {
    header('Content-Type: application/json; charset=utf-8');
    $requestId = intval($_POST['request_id'] ?? 0);
    $staffComment = trim($_POST['staff_comment'] ?? '');

    $stmtCheck = $conn->prepare("SELECT requestId, roomNumber, category FROM ServiceRequest sr JOIN Room r ON sr.roomId = r.roomId WHERE sr.requestId = ? AND sr.assignedTo = ?");
    $stmtCheck->execute([$requestId, $staffId]);
    $jobInfo = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($jobInfo) {
        $stmtUpdate = $conn->prepare("UPDATE ServiceRequest SET staffComment = ?, staffCommentedAt = NOW() WHERE requestId = ?");
        if ($stmtUpdate->execute([$staffComment, $requestId])) {
            echo json_encode([
                'success' => true,
                'message' => 'บันทึกรายงานปัญหาถึงแอดมินเรียบร้อยแล้ว!',
                'staffComment' => $staffComment,
                'staffCommentedAt' => date('d/m/Y H:i')
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลงานนี้ หรือคุณไม่มีสิทธิ์แก้ไข']);
    }
    exit;
}

// --- 🌟 ระบบบันทึก/รายงานปัญหาเพิ่มเติมจากช่าง (ส่งตรงถึงแอดมินเท่านั้น) 🌟 ---



if (isset($_POST['save_staff_comment'])) {



    $requestId = intval($_POST['request_id']);



    $staffComment = trim($_POST['staff_comment']);



    



    // ตรวจสอบสิทธิ์ว่างานนี้เป็นของพนักงานคนนี้ หรือได้รับมอบหมาย



    $stmtCheck = $conn->prepare("SELECT requestId, roomNumber, category FROM ServiceRequest sr JOIN Room r ON sr.roomId = r.roomId WHERE sr.requestId = ? AND sr.assignedTo = ?");



    $stmtCheck->execute([$requestId, $staffId]);



    $jobInfo = $stmtCheck->fetch(PDO::FETCH_ASSOC);







    if ($jobInfo) {



        $stmtUpdate = $conn->prepare("UPDATE ServiceRequest SET staffComment = ?, staffCommentedAt = NOW() WHERE requestId = ?");



        if ($stmtUpdate->execute([$staffComment, $requestId])) {



            $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-900 rounded-r-xl shadow-sm text-sm font-medium flex items-center justify-between gap-3'>



                <div class='flex items-center gap-2.5'>



                    <i data-lucide='check-circle-2' class='w-5 h-5 text-amber-600 shrink-0'></i>



                    <div>



                        <span class='font-bold text-slate-800'>บันทึกรายงานปัญหาถึงแอดมินเรียบร้อยแล้ว! (ห้อง {$jobInfo['roomNumber']} • {$jobInfo['category']})</span>



                        <div class='text-xs text-amber-800 mt-0.5 font-sans'>🔒 <strong>ข้อความนี้ส่งถึงแอดมิน/นิติบุคคลเท่านั้น</strong> ลูกบ้านจะไม่เห็นข้อความนี้</div>



                    </div>



                </div>



            </div>";



        }



    } else {



        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> ไม่พบข้อมูลงานหรือไม่ได้รับอนุญาตให้แก้ไข</div>";



    }



}







// --- ดึงข้อมูลงานที่นิติบุคคลมอบหมายมาให้พนักงานคนนี้ (ดึงเฉพาะสถานะ In Progress เท่านั้น) ---



$stmtJobs = $conn->prepare("



    SELECT sr.*, r.roomNumber, u.fullName as tenantName, u.phone as tenantPhone



    FROM ServiceRequest sr 



    JOIN Room r ON sr.roomId = r.roomId 



    JOIN User u ON sr.tenantId = u.userId



    WHERE sr.status = 'In Progress' AND sr.assignedTo = ?



    ORDER BY sr.preferredDate ASC, sr.requestId ASC



");



$stmtJobs->execute([$staffId]);



$activeJobs = $stmtJobs->fetchAll(PDO::FETCH_ASSOC);







// --- ดึงประวัติงานที่ทำเสร็จแล้ว (Completed) ล่าสุดของพนักงานคนนี้ ---



$stmtCompleted = $conn->prepare("



    SELECT sr.*, r.roomNumber, u.fullName as tenantName, u.phone as tenantPhone 



    FROM ServiceRequest sr 



    JOIN Room r ON sr.roomId = r.roomId 



    JOIN User u ON sr.tenantId = u.userId



    WHERE sr.status IN ('Pending Approval', 'Completed') AND sr.assignedTo = ?
    ORDER BY COALESCE(sr.completedAt, sr.requestDate) DESC, sr.requestId DESC LIMIT 50



");



$stmtCompleted->execute([$staffId]);



$completedJobs = $stmtCompleted->fetchAll(PDO::FETCH_ASSOC);







// รวบรวมข้อมูลสำหรับ Job Detail Modal (ทั้งงานที่ต้องทำ และ งานที่ทำเสร็จแล้ว)



$allJobsData = [];



$mergedJobs = array_merge($activeJobs, $completedJobs);



foreach ($mergedJobs as $j) {



    $assignedDate = !empty($j['assignedAt']) ? $j['assignedAt'] : $j['requestDate'];



    $dur = (!empty($assignedDate) && !empty($j['completedAt'])) ? duration_th($assignedDate, $j['completedAt']) : ((!empty($j['requestDate']) && !empty($j['completedAt'])) ? duration_th($j['requestDate'], $j['completedAt']) : '');



    



    $allJobsData[$j['requestId']] = [



        'requestId' => (int)$j['requestId'],



        'reqCode' => 'REQ-' . str_pad($j['requestId'], 4, '0', STR_PAD_LEFT),



        'roomId' => (int)$j['roomId'],



        'roomNumber' => $j['roomNumber'],



        'category' => $j['category'],



        'description' => $j['description'],



        'preferredDate' => !empty($j['preferredDate']) ? date('d/m/Y', strtotime($j['preferredDate'])) : '-',



        'preferredTime' => $j['preferredTime'] ?? '',



        'allowEntry' => (int)$j['allowEntry'],



        'status' => $j['status'],



        'requestDate' => !empty($j['requestDate']) ? date('d/m/Y H:i', strtotime($j['requestDate'])) : '-',



        'requestDateRaw' => $j['requestDate'] ?? '',



        'timeAgoRequest' => !empty($j['requestDate']) ? time_ago_th($j['requestDate']) : '',



        'assignedAt' => !empty($j['assignedAt']) ? date('d/m/Y H:i', strtotime($j['assignedAt'])) : (!empty($j['requestDate']) ? date('d/m/Y H:i', strtotime($j['requestDate'])) : '-'),



        'assignedAtRaw' => $j['assignedAt'] ?? ($j['requestDate'] ?? ''),



        'timeAgoAssigned' => !empty($j['assignedAt']) ? time_ago_th($j['assignedAt']) : (!empty($j['requestDate']) ? time_ago_th($j['requestDate']) : ''),



        'completedAt' => !empty($j['completedAt']) ? date('d/m/Y H:i', strtotime($j['completedAt'])) : '',



        'completedAtRaw' => $j['completedAt'] ?? '',



        'timeAgoCompleted' => !empty($j['completedAt']) ? time_ago_th($j['completedAt']) : '',



        'duration' => $dur,



        'tenantName' => $j['tenantName'] ?? 'ลูกบ้าน',



        'tenantPhone' => $j['tenantPhone'] ?? '',



        'requestImage' => $j['requestImage'] ?? '',



        'completionImage' => $j['completionImage'] ?? '',



        'estimatedHours' => (int)($j['estimatedHours'] ?? 2),



        'staffComment' => $j['staffComment'] ?? '',



        'staffCommentedAt' => !empty($j['staffCommentedAt']) ? date('d/m/Y H:i', strtotime($j['staffCommentedAt'])) : '',

        'adminRemark' => $j['adminRemark'] ?? '',

        'adminApprovedAt' => !empty($j['adminApprovedAt']) ? date('d/m/Y H:i', strtotime($j['adminApprovedAt'])) : ''



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
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Kanit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest"></script>
  <script>
    tailwind.config = {
      darkMode: ['selector', '[data-theme="dark"]'],
      theme: {
        extend: {
          fontFamily: {
            sans: ['Prompt', 'sans-serif'],
            display: ['Kanit', 'sans-serif'],
            mono: ['Plus Jakarta Sans', 'monospace']
          }
        }
      }
    }
  </script>
  <style>
    .modern-job-card {
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .modern-job-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 16px 32px -10px rgba(15, 23, 42, 0.08), 0 0 0 1px rgba(225, 29, 72, 0.12);
    }
  </style>
  <script>
    // ข้อมูลงานทั้งหมดสำหรับ Staff Job Detail Modal
    window.staffJobs = <?php echo json_encode($allJobsData, JSON_UNESCAPED_UNICODE); ?>;







function previewCompletionImage(event) {
        const file = event.target.files ? event.target.files[0] : null;
        const placeholder = document.getElementById('staff_completion_upload_placeholder');
        const previewBox = document.getElementById('staff_completion_img_preview_box');
        const previewImg = document.getElementById('staff_completion_img_preview');
        if (file && previewBox && previewImg) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                if (placeholder) placeholder.classList.add('hidden');
                previewBox.classList.remove('hidden');
                if (typeof lucide !== 'undefined') lucide.createIcons();
            };
            reader.readAsDataURL(file);
        }
    }

    function clearCompletionImagePreview() {
        const fileInput = document.getElementById('staff_modal_completion_file');
        const placeholder = document.getElementById('staff_completion_upload_placeholder');
        const previewBox = document.getElementById('staff_completion_img_preview_box');
        const previewImg = document.getElementById('staff_completion_img_preview');
        if (fileInput) fileInput.value = '';
        if (previewBox) previewBox.classList.add('hidden');
        if (placeholder) placeholder.classList.remove('hidden');
        if (previewImg) previewImg.src = '';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function openStaffHandoverPreview() {
        const previewImg = document.getElementById('staff_completion_img_preview');
        if (previewImg && previewImg.src) {
            const roomEl = document.getElementById('staff_detail_room');
            const roomNum = roomEl ? roomEl.textContent.trim() : '';
            openImageModal(previewImg.src, 'รูปถ่ายผลงานการซ่อม (หลังทำ - ห้อง ' + roomNum + ')');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const completeForm = document.getElementById('staff_job_complete_form');
        if (completeForm) {
            completeForm.addEventListener('submit', function(e) {
                const fileInput = document.getElementById('staff_modal_completion_file');
                if (!fileInput || !fileInput.files || !fileInput.files.length) {
                    e.preventDefault();
                    alert('กรุณาถ่ายภาพหรือเลือกภาพผลงานก่อนยืนยันการส่งงาน');
                    if (fileInput) fileInput.click();
                    return false;
                }
            });
        }
    });


    function openStaffJobDetailModal(reqId, focusHandover = false) {



        const job = window.staffJobs ? window.staffJobs[reqId] : null;



        if (!job) return;







        // ข้อมูลหลัก



        document.getElementById('staff_detail_code').textContent = job.reqCode;



        document.getElementById('staff_detail_room').textContent = job.roomNumber;



        document.getElementById('staff_detail_category').textContent = job.category;



        document.getElementById('staff_detail_desc').textContent = job.description || '-';







        // กำหนดเวลา



        const hoursBadge = document.getElementById('staff_detail_hours_badge');



        if (hoursBadge) {



            hoursBadge.textContent = '⏱️ กำหนดเวลา ' + (job.estimatedHours || 2) + ' ชม.';



        }







        // ป้ายสถานะ



        const statusEl = document.getElementById('staff_detail_status_badge');



        if (job.status === 'Completed') {



            statusEl.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1.5';



            statusEl.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> เสร็จสิ้นแล้ว';



        } else {



            statusEl.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-rose-50 text-blue-700 border border-blue-200 flex items-center gap-1.5';



            statusEl.innerHTML = '<i data-lucide="tools" class="w-3.5 h-3.5 text-blue-600"></i> ได้รับมอบหมาย (กำลังดำเนินการ)';



        }







        // นัดหมายเข้าห้อง



        const prefBox = document.getElementById('staff_detail_appointment');



        if (job.preferredDate && job.preferredDate !== '-') {



            prefBox.classList.remove('hidden');



            document.getElementById('staff_detail_pref_text').textContent = 'นัดเข้า: ' + job.preferredDate + (job.preferredTime ? ' (' + job.preferredTime + ')' : '');



        } else {



            prefBox.classList.add('hidden');



        }







        // สิทธิ์การเข้าห้อง



        const entryBadge = document.getElementById('staff_detail_entry_badge');



        if (job.allowEntry == 1) {



            entryBadge.className = 'text-xs font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-xl border border-emerald-200 flex items-center gap-1.5';



            entryBadge.innerHTML = '<i data-lucide="key" class="w-3.5 h-3.5"></i> อนุญาตใช้กุญแจสำรอง';



        } else {



            entryBadge.className = 'text-xs font-bold text-slate-600 bg-slate-100 px-2.5 py-1 rounded-xl border border-rose-100/80 flex items-center gap-1.5';



            entryBadge.innerHTML = '<i data-lucide="lock" class="w-3.5 h-3.5"></i> ต้องมีผู้เช่าอยู่ห้อง';



        }







        // ข้อมูลผู้แจ้ง & เบอร์โทร



        document.getElementById('staff_detail_tenant_name').textContent = job.tenantName || 'ลูกบ้าน';



        const phoneBtn = document.getElementById('staff_detail_tenant_phone_btn');



        if (job.tenantPhone) {



            phoneBtn.href = 'tel:' + job.tenantPhone;



            phoneBtn.querySelector('.phone-text').textContent = job.tenantPhone;



            phoneBtn.classList.remove('hidden');



        } else {



            phoneBtn.classList.add('hidden');



        }







        // ไทม์ไลน์เวลา: 1. แจ้งเมื่อ



        document.getElementById('staff_detail_req_date').textContent = job.requestDate + ' น.';



        document.getElementById('staff_detail_req_ago').textContent = '';







        // ไทม์ไลน์เวลา: 2. รับงานเมื่อ



        document.getElementById('staff_detail_assigned_date').textContent = job.assignedAt + ' น.';



        document.getElementById('staff_detail_assigned_ago').textContent = '';



        const estHoursEl = document.getElementById('staff_detail_est_hours');



        if (estHoursEl) {



            estHoursEl.textContent = '⏱️ กำหนดเวลา: ' + (job.estimatedHours || 2) + ' ชม.';



        }







        // ไทม์ไลน์เวลา: 3. จบงานเมื่อ



        const completedBox = document.getElementById('staff_detail_completed_box');



        const completedDateEl = document.getElementById('staff_detail_completed_date');



        const durationEl = document.getElementById('staff_detail_duration');







        if (job.status === 'Completed' && job.completedAt) {



            completedDateEl.textContent = job.completedAt + ' น.';



            durationEl.textContent = job.duration ? '⏱️ รวมเวลา: ' + job.duration : '';



            durationEl.classList.remove('hidden');



            completedBox.classList.remove('bg-rose-50/30', 'border-rose-100/80');



            completedBox.classList.add('bg-emerald-50/70', 'border-emerald-200/80');



        } else {



            completedDateEl.innerHTML = '<span class="text-blue-600 font-semibold flex items-center gap-1.5"><span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span></span> กำลังดำเนินการ</span>';



            durationEl.classList.add('hidden');



            completedBox.classList.remove('bg-emerald-50/70', 'border-emerald-200/80');



            completedBox.classList.add('bg-rose-50/30', 'border-rose-100/80');



        }







        // คอลัมน์รูปก่อนทำ



        const beforeContainer = document.getElementById('staff_detail_before_img_container');



        if (job.requestImage) {



            beforeContainer.innerHTML = `



                <div class="relative group cursor-pointer rounded-2xl overflow-hidden border border-amber-200 shadow-sm bg-slate-950 aspect-video flex items-center justify-center" onclick="openImageModal('${job.requestImage}', 'รูปก่อนทำ (ปัญหาจากลูกบ้าน - ห้อง ${job.roomNumber})')">



                    <img src="${job.requestImage}" class="w-full h-full object-cover transition duration-300 group-hover:scale-105" alt="ก่อนทำ">



                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">



                        <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม</span>



                    </div>



                </div>`;



        } else {



            beforeContainer.innerHTML = `



                <div class="h-32 rounded-2xl bg-white border border-dashed border-amber-200/80 flex flex-col items-center justify-center text-slate-400 text-xs">



                    <i data-lucide="image-off" class="w-6 h-6 mb-1 text-slate-300"></i>



                    <span>ลูกบ้านไม่ได้แนบรูป</span>



                </div>`;



        }







        // คอลัมน์รูปหลังทำ และฟอร์มส่งมอบงานในหน้าเดียว
        const afterContainer = document.getElementById('staff_detail_after_img_container');
        const afterSection = document.getElementById('staff_detail_after_img_section');
        const handoverSection = document.getElementById('staff_detail_handover_form_container');
        const inlineCommentBox = document.getElementById('staff_detail_inline_comment_box');
        const reqIdInput = document.getElementById('staff_detail_form_req_id');
        if (reqIdInput) reqIdInput.value = job.requestId;
        clearCompletionImagePreview();
        const formComment = document.getElementById('staff_modal_comment_textarea');
        if (formComment) formComment.value = job.staffComment || '';

        if (job.status === 'Completed' || job.status === 'Pending Approval') {
            if (handoverSection) handoverSection.classList.add('hidden');
            if (afterSection) afterSection.classList.remove('hidden');
            if (inlineCommentBox) inlineCommentBox.classList.remove('hidden');

            const statusNote = (job.status === 'Pending Approval') 
                ? '<div class="mt-2 text-center text-xs font-bold text-amber-800 bg-amber-50 p-2.5 rounded-xl border border-amber-200 flex items-center justify-center gap-1.5"><i data-lucide="clock" class="w-4 h-4 text-amber-600"></i> ส่งงานเรียบร้อยแล้ว อยู่ระหว่างรอฝ่ายนิติบุคคลตรวจสอบและอนุมัติงาน</div>' 
                : '<div class="mt-2 text-center text-xs font-bold text-emerald-800 bg-emerald-50 p-2.5 rounded-xl border border-emerald-200 flex items-center justify-center gap-1.5"><i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600"></i> นิติบุคคลตรวจสอบและอนุมัติงานเรียบร้อยแล้ว</div>';

            if (job.completionImage) {
                afterContainer.innerHTML = `
                    <div class="relative group cursor-pointer rounded-2xl overflow-hidden border border-emerald-300 shadow-sm bg-slate-950 aspect-video flex items-center justify-center" onclick="openImageModal('${job.completionImage}', 'รูปหลังทำ (ผลงานที่ส่งมอบ - ห้อง ${job.roomNumber})')">
                        <img src="${job.completionImage}" class="w-full h-full object-cover transition duration-300 group-hover:scale-105" alt="หลังทำ">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">
                            <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม</span>
                        </div>
                    </div>` + statusNote;
            } else {
                afterContainer.innerHTML = `
                    <div class="h-32 rounded-2xl bg-white border border-dashed border-emerald-200/80 flex flex-col items-center justify-center text-slate-400 text-xs">
                        <i data-lucide="image-off" class="w-6 h-6 mb-1 text-slate-300"></i>
                        <span>ไม่มีรูปภาพผลงาน</span>
                    </div>` + statusNote;
            }
        } else {
            // ยังไม่เสร็จสิ้น -> แสดงฟอร์มส่งมอบงาน และซ่อนรูป AFTER
            if (handoverSection) handoverSection.classList.remove('hidden');
            if (afterSection) afterSection.classList.add('hidden');
            if (inlineCommentBox) inlineCommentBox.classList.add('hidden');
            if (afterContainer) afterContainer.innerHTML = '';
        }

        // บันทึกปัญหาเพิ่มเติมถึงแอดมิน (กล่องพิมพ์และแก้ไขได้ทันทีในหน้าเดียวกัน)
        window.currentActiveStaffJob = job;
        const staffCommTextarea = document.getElementById('staff_detail_comment_textarea');
        const staffCommTime = document.getElementById('staff_detail_comment_timestamp');
        const staffCommBtnText = document.getElementById('staff_detail_comment_btn_text');

        if (staffCommTextarea) {
            staffCommTextarea.value = job.staffComment || '';
        }
        if (staffCommTime) {
            staffCommTime.innerHTML = job.staffCommentedAt 
                ? `<i data-lucide="clock" class="w-3.5 h-3.5 text-amber-600"></i> บันทึกเมื่อ: ${job.staffCommentedAt} น.` 
                : '<span class="text-slate-400">สามารถพิมพ์และกดบันทึกได้ทันที</span>';
        }
        if (staffCommBtnText) {
            staffCommBtnText.textContent = job.staffComment ? 'บันทึกการแก้ไข' : 'บันทึกรายงานปัญหา';
        }







        lucide.createIcons();
        document.getElementById('staffJobDetailModal').classList.remove('hidden');

        if (focusHandover) {
            setTimeout(() => {
                const handoverEl = document.getElementById('staff_detail_handover_form_container');
                if (handoverEl && !handoverEl.classList.contains('hidden')) {
                    handoverEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 250);
        }



    }







    function closeStaffJobDetailModal() {



        const modal = document.getElementById('staffJobDetailModal');



        if (modal) modal.classList.add('hidden');



    }







    // ปิด Modal เมื่อกดปุ่ม Escape



    document.addEventListener('keydown', function(e) {



        if (e.key === 'Escape') {



            closeStaffJobDetailModal();



            closeCompleteModal();



            closeStaffCommentModal();



            closeImageModal();



        }



    });



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







  <!-- Top Navigation -->



  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-rose-100/80 shadow-sm">



    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">



      <div class="flex items-center justify-between h-16">



        <div class="flex items-center space-x-3">



          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-rose-600 via-rose-500 to-pink-600 flex items-center justify-center text-white shadow-md">



            <i data-lucide="hard-hat" class="w-5 h-5"></i>



          </div>



          <div>



            <div class="flex items-center space-x-2">



              <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>



              <span class="text-[10px] <?php echo $isMaid ? 'bg-purple-50 text-purple-700 border-purple-200' : 'bg-blue-50 text-blue-700 border-blue-200'; ?> font-bold px-2.5 py-0.5 rounded-full border"><?php echo $roleTitle; ?></span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium"><?php echo $subTitle; ?></p>



          </div>



        </div>







        <div class="flex items-center space-x-3">



          <a href="profile.php" class="flex items-center space-x-2 hover:opacity-80 transition" title="โปรไฟล์ของฉัน">



            <div class="hidden sm:block text-right mr-1">



              <div class="text-xs font-semibold text-slate-700 hover:text-indigo-600 transition"><?php echo htmlspecialchars($staffName); ?></div>



            </div>



            <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-xs border border-indigo-200">



              <?php echo $initials; ?>



            </div>



          </a>



          <!-- 🌟 สลับธีม 2 สี (มืด / สว่าง) 🌟 -->
          <?php include 'includes/theme_toggle.php'; ?>

          <div class="w-px h-6 bg-slate-200 hidden sm:block"></div> 



          <a href="logout.php" onclick="return confirm('ออกจากระบบ?');" class="p-2 text-rose-500 hover:bg-rose-50 rounded-xl transition" title="ออกจากระบบ">



              <i data-lucide="log-out" class="w-5 h-5"></i>



          </a>



        </div>



      </div>



    </div>



  </header>







  <main class="flex-1 max-w-5xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">



    



    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">
      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
          <div class="flex items-center gap-2 flex-wrap mb-2">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
              <i data-lucide="<?php echo $isMaid ? 'sparkles' : 'wrench'; ?>" class="w-3.5 h-3.5 <?php echo $isMaid ? 'text-purple-600' : 'text-blue-600'; ?>"></i>
              <span><?php echo $deptTitle; ?></span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="clipboard-list" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>งานปัจจุบันของคุณ: <?php echo count($activeJobs); ?> รายการ</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-800 bg-emerald-50 border border-emerald-200 px-3 py-1 rounded-full">
              <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-500"></i>
              <span>งานเสร็จสิ้นของคุณ: <?php echo count($completedJobs); ?> รายการ</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            <?php echo $pageHeading; ?>
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            รายการงานเฉพาะที่คุณได้รับมอบหมาย ตรวจสอบสถานะ และอัปเดตผลการดำเนินงานของตนเอง
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-3 shrink-0">
          <a href="profile.php" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">
            <i data-lucide="user" class="w-4 h-4 text-slate-500"></i>
            <span>ข้อมูลส่วนตัว</span>
          </a>
        </div>
      </div>
    </div>







    <div id="alert-container">



        <?php echo $message; ?>



    </div>







    <!-- 🌟 แถบแสดงสถานะความพร้อมของพนักงาน (ว่าง / ติดงาน) 🌟 -->



    <?php if(count($activeJobs) > 0): ?>



        <div class="mb-6 p-4 rounded-2xl bg-amber-50/90 border border-amber-200/90 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">



            <div class="flex items-center gap-3">



                <span class="relative flex h-3.5 w-3.5 shrink-0">



                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>



                    <span class="relative inline-flex rounded-full h-3.5 w-3.5 bg-amber-500"></span>



                </span>



                <div>



                    <div class="text-sm font-bold text-amber-900 flex items-center gap-2">



                        <span>สถานะการทำงานของคุณ:</span>



                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-200 text-amber-900 border border-amber-300">🔴 ติดงาน (มีงานที่รับมอบหมาย <?php echo count($activeJobs); ?> รายการ)</span>



                    </div>



                    <p class="text-xs text-amber-700 mt-0.5">ระบบจะล็อกไม่ให้แอดมินมอบหมายงานซ้อนให้คุณ เมื่อคุณกดส่งมอบงานเสร็จสิ้น สถานะจะถูกเปลี่ยนเป็น "ว่าง" โดยอัตโนมัติ</p>



                </div>



            </div>



            <div class="text-xs font-bold text-amber-800 bg-white/80 px-3 py-1.5 rounded-xl border border-amber-200 shrink-0 flex items-center gap-1.5">



                <i data-lucide="lock" class="w-3.5 h-3.5 text-amber-600"></i> ล็อกไม่ให้รับงานซ้อน



            </div>



        </div>



    <?php else: ?>



        <div class="mb-6 p-4 rounded-2xl bg-emerald-50/90 border border-emerald-200/90 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">



            <div class="flex items-center gap-3">



                <span class="relative flex h-3.5 w-3.5 shrink-0">



                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>



                    <span class="relative inline-flex rounded-full h-3.5 w-3.5 bg-emerald-500"></span>



                </span>



                <div>



                    <div class="text-sm font-bold text-emerald-900 flex items-center gap-2">



                        <span>สถานะการทำงานของคุณ:</span>



                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-200 text-emerald-900 border border-emerald-300">🟢 ว่าง (พร้อมรับงานใหม่)</span>



                    </div>



                    <p class="text-xs text-emerald-700 mt-0.5">ขณะนี้คุณไม่มีงานค้าง แอดมินสามารถเลือกมอบหมายงานซ่อมหรือบริการใหม่ให้คุณได้ทันที</p>



                </div>



            </div>



            <div class="text-xs font-bold text-emerald-800 bg-white/80 px-3 py-1.5 rounded-xl border border-emerald-200 shrink-0 flex items-center gap-1.5">



                <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> พร้อมรับงาน



            </div>



        </div>



    <?php endif; ?>







    <!-- 🌟 แท็บสลับหน้า (งานปัจจุบัน / ประวัติงานเสร็จ) 🌟 -->
    <div class="mb-6 flex gap-2 border-b border-rose-100/80 pb-px overflow-x-auto hide-scrollbar">
        <button onclick="switchTab('active')" id="tab-active" class="<?php echo ($activeTab === 'completed' ? 'px-5 py-2.5 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition' : 'px-5 py-2.5 text-sm font-bold border-b-2 border-indigo-600 text-indigo-600 flex items-center gap-2 whitespace-nowrap transition'); ?>">
            <i data-lucide="<?php echo $isMaid ? 'sparkles' : 'hammer'; ?>" class="w-4 h-4"></i> งานที่ต้องทำ (<?php echo count($activeJobs); ?>)
        </button>
        <button onclick="switchTab('completed')" id="tab-completed" class="<?php echo ($activeTab === 'completed' ? 'px-5 py-2.5 text-sm font-bold border-b-2 border-indigo-600 text-indigo-600 flex items-center gap-2 whitespace-nowrap transition' : 'px-5 py-2.5 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition'); ?>">
            <i data-lucide="check-circle-2" class="w-4 h-4"></i> งานที่ทำเสร็จแล้ว (<?php echo count($completedJobs); ?>)
        </button>
    </div>

    <!-- ================= แท็บ 1: งานที่ได้รับมอบหมาย ================= -->
    <div id="content-active" class="space-y-4 <?php echo ($activeTab === 'completed' ? 'hidden' : ''); ?>">



        <?php if(count($activeJobs) > 0): foreach($activeJobs as $job): 



            $assignedTime = !empty($job['assignedAt']) ? $job['assignedAt'] : $job['requestDate'];



        ?>



        <div onclick="openStaffJobDetailModal(<?php echo $job['requestId']; ?>)" class="bg-white p-5 rounded-3xl border-l-4 border-l-blue-500 border-y border-r border-rose-100/80 shadow-sm hover:shadow-md transition cursor-pointer group">



            <div class="flex flex-col md:flex-row gap-5">



                



                <!-- ข้อมูลงาน -->



                <div class="flex-1 min-w-0">



                    <div class="flex items-center gap-2 mb-2 flex-wrap">



                        <span class="text-xs font-bold px-2.5 py-1 rounded-xl bg-slate-800 text-white shadow-xs font-mono">



                            ห้อง <?php echo htmlspecialchars($job['roomNumber']); ?>



                        </span>



                        



                        <span class="text-[10px] text-blue-700 font-bold bg-rose-50 px-2.5 py-1 rounded-xl border border-blue-200 flex items-center gap-1">



                            <i data-lucide="tools" class="w-3 h-3"></i> ได้รับมอบหมาย



                        </span>







                        <span class="text-[10px] text-slate-500 font-mono bg-slate-100 px-2 py-0.5 rounded-lg border border-rose-100/80">



                            REQ-<?php echo str_pad($job['requestId'], 4, '0', STR_PAD_LEFT); ?>



                        </span>







                        <?php if(!empty($job['preferredDate'])): ?>



                            <span class="text-[10px] text-indigo-700 font-bold bg-indigo-50 px-2.5 py-1 rounded-xl border border-indigo-100 flex items-center gap-1">



                                <i data-lucide="calendar-clock" class="w-3 h-3"></i> นัดเข้า: <?php echo date("d/m/Y", strtotime($job['preferredDate'])); ?>



                            </span>



                        <?php endif; ?>



                        



                        <?php if($job['allowEntry'] == 1): ?>



                            <span class="text-[10px] text-emerald-700 font-bold bg-emerald-50 px-2.5 py-1 rounded-xl border border-emerald-100 flex items-center gap-1">



                                <i data-lucide="key" class="w-3 h-3"></i> อนุญาตใช้กุญแจสำรอง



                            </span>



                        <?php else: ?>



                            <span class="text-[10px] text-slate-500 font-bold bg-slate-100 px-2.5 py-1 rounded-xl border border-rose-100/80 flex items-center gap-1">



                                <i data-lucide="lock" class="w-3 h-3"></i> ต้องมีผู้เช่าอยู่ห้อง



                            </span>



                        <?php endif; ?>



                    </div>

                    <?php if(!empty($job['adminRemark'])): ?>
                        <div class="mb-3 p-3 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2">
                            <i data-lucide="alert-triangle" class="w-4 h-4 text-rose-600 shrink-0"></i>
                            <span><b>แอดมินแจ้งส่งกลับให้แก้ไข:</b> <?php echo htmlspecialchars($job['adminRemark']); ?></span>
                        </div>
                    <?php endif; ?>

                    <h4 class="font-bold text-slate-800 text-lg group-hover:text-blue-600 transition-colors"><?php echo htmlspecialchars($job['category']); ?></h4>



                    <p class="text-sm text-slate-600 mt-2 bg-rose-50/30 p-3.5 rounded-2xl border border-rose-100/60 whitespace-pre-line leading-relaxed"><?php echo htmlspecialchars($job['description']); ?></p>



                    



                    <div id="staff_card_comment_section_<?php echo $job['requestId']; ?>">
<?php if(!empty($job['staffComment'])): ?>



                        <div class="mt-3 p-3 bg-amber-50/90 border border-amber-200/90 rounded-2xl flex items-start justify-between gap-3 text-xs">



                            <div class="flex items-start gap-2 text-amber-950">



                                <i data-lucide="message-square-warning" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5"></i>



                                <div>



                                    <span class="font-bold text-amber-900 block">รายงานปัญหาถึงแอดมิน:</span>



                                    <span class="text-slate-700 whitespace-pre-line"><?php echo htmlspecialchars($job['staffComment']); ?></span>



                                    <?php if(!empty($job['staffCommentedAt'])): ?>



                                        <span class="text-[10px] text-amber-800/80 block mt-0.5 font-mono">บันทึกเมื่อ: <?php echo date("d/m/Y H:i", strtotime($job['staffCommentedAt'])); ?> น.</span>



                                    <?php endif; ?>
</div>



                                </div>



                            </div>



                            <button type="button" onclick="event.stopPropagation(); openStaffCommentModal(<?php echo $job['requestId']; ?>, '<?php echo htmlspecialchars(addslashes($job['roomNumber'])); ?>', 'REQ-<?php echo str_pad($job['requestId'], 4, '0', STR_PAD_LEFT); ?>', '<?php echo htmlspecialchars(addslashes($job['staffComment'])); ?>')" class="text-[11px] font-bold text-amber-800 hover:text-amber-950 underline shrink-0 cursor-pointer">



                                แก้ไข



                            </button>



                        </div>



                    <?php endif; ?>



                    



                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-500">



                        <span class="inline-flex items-center gap-1.5 font-medium bg-amber-50 text-amber-900 px-2.5 py-1 rounded-xl border border-amber-200/80">



                            <i data-lucide="send" class="w-3.5 h-3.5 text-amber-600"></i>



                            <span>แจ้งเมื่อ: <b><?php echo date("d/m/Y H:i", strtotime($job['requestDate'])); ?> น.</b></span>







                        </span>







                        <span class="inline-flex items-center gap-1.5 font-medium bg-rose-50 text-blue-900 px-2.5 py-1 rounded-xl border border-blue-200/80">



                            <i data-lucide="wrench" class="w-3.5 h-3.5 text-blue-600"></i>



                            <span>รับงานเมื่อ: <b><?php echo date("d/m/Y H:i", strtotime($assignedTime)); ?> น.</b></span>







                        </span>







                        <?php 



                            $estH = intval($job['estimatedHours'] ?? 2);



                            $jobAssignTs = !empty($job['assignedAt']) ? strtotime($job['assignedAt']) : (!empty($job['requestDate']) ? strtotime($job['requestDate']) : time());



                            $jobExpectedEndTs = $jobAssignTs + ($estH * 3600);



                        ?>



                        <span class="inline-flex items-center gap-1.5 font-medium bg-purple-50 text-purple-900 px-2.5 py-1 rounded-xl border border-rose-200/80">



                            <i data-lucide="clock" class="w-3.5 h-3.5 text-purple-600"></i>



                            <span>กำหนดเวลา: <b><?php echo $estH; ?> ชม.</b> (ถึง ~<b><?php echo date('H:i', $jobExpectedEndTs); ?> น.</b>)</span>



                        </span>







                        <span class="flex items-center gap-1 font-medium ml-1">



                            <i data-lucide="user" class="w-3.5 h-3.5 text-slate-400"></i> ผู้แจ้ง: <b><?php echo htmlspecialchars($job['tenantName'] ?? 'ลูกบ้าน'); ?></b>



                        </span>







                        <?php if(!empty($job['tenantPhone'])): ?>



                            <a href="tel:<?php echo htmlspecialchars($job['tenantPhone']); ?>" onclick="event.stopPropagation()" class="flex items-center gap-1 text-blue-600 hover:underline font-semibold bg-rose-50/50 px-2 py-0.5 rounded-lg border border-blue-100">



                                <i data-lucide="phone" class="w-3.5 h-3.5"></i> <?php echo htmlspecialchars($job['tenantPhone']); ?>



                            </a>



                        <?php endif; ?>



                    </div>



                </div>



                



                <!-- ปุ่ม Action & รูปก่อนทำ -->



                <div class="flex flex-col gap-2 shrink-0 w-full md:w-52 pt-2 md:pt-0 justify-between">



                    <div class="flex items-center gap-2">



                        <?php if(!empty($job['requestImage'])): ?>



                            <div class="relative group/bimg cursor-pointer w-14 h-14 rounded-xl overflow-hidden border border-rose-100/80 shadow-2xs shrink-0 bg-slate-900" onclick="event.stopPropagation(); openImageModal('<?php echo htmlspecialchars($job['requestImage']); ?>', 'รูปก่อนทำ (ปัญหาจากลูกบ้าน)')" title="คลิกดูรูปก่อนทำ">



                                <img src="<?php echo htmlspecialchars($job['requestImage']); ?>" class="w-full h-full object-cover transition group-hover/bimg:scale-105" alt="ก่อนทำ">



                                <span class="absolute bottom-0 inset-x-0 bg-black/60 text-white text-[9px] font-bold text-center py-0.2">ก่อนทำ</span>



                            </div>



                        <?php endif; ?>



                        <div class="flex-1 min-w-0">



                            <button type="button" onclick="event.stopPropagation(); openStaffJobDetailModal(<?php echo $job['requestId']; ?>)" class="w-full py-2 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 text-indigo-700 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer">



                                <i data-lucide="eye" class="w-3.5 h-3.5 text-indigo-600"></i> ดูแบบเต็ม



                            </button>



                        </div>



                    </div>







                    <!-- ปุ่มรายงานปัญหาเพิ่มเติมถึงแอดมิน (เห็นเฉพาะแอดมิน) -->



                    <button type="button" onclick="event.stopPropagation(); openStaffCommentModal(<?php echo $job['requestId']; ?>, '<?php echo htmlspecialchars(addslashes($job['roomNumber'])); ?>', 'REQ-<?php echo str_pad($job['requestId'], 4, '0', STR_PAD_LEFT); ?>', '<?php echo htmlspecialchars(addslashes($job['staffComment'] ?? '')); ?>')" class="w-full py-2 bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-800 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer">



                        <i data-lucide="message-square-warning" class="w-3.5 h-3.5 text-amber-600"></i>



                        <span id="staff_card_action_text_<?php echo $job['requestId']; ?>"><?php echo !empty($job['staffComment']) ? 'แก้ไขรายงานปัญหา' : 'รายงานปัญหาถึงแอดมิน'; ?></span>



                    </button>



                    



                    <!-- ปุ่มส่งงาน (บังคับอัปโหลดรูป) -->



                    <button type="button" onclick="event.stopPropagation(); openStaffJobDetailModal(<?php echo $job['requestId']; ?>, true)" class="w-full py-3 bg-rose-500 hover:bg-rose-600 text-white rounded-xl text-sm font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-2 mt-auto cursor-pointer">



                        <i data-lucide="check-square" class="w-4 h-4"></i> ส่งมอบงาน



                    </button>



                </div>



            </div>



        </div>



        <?php endforeach; else: ?>



        <div class="text-center py-16 bg-white rounded-3xl border border-rose-100/80 shadow-sm">



            <div class="w-16 h-16 bg-rose-50/30 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300">



                <i data-lucide="coffee" class="w-8 h-8"></i>



            </div>



            <h3 class="text-lg font-bold text-slate-700 font-display">ไม่มีงานค้าง</h3>



            <p class="text-sm text-slate-500 mt-1">ขณะนี้ยังไม่มีงานที่นิติบุคคลมอบหมายให้คุณครับ</p>



        </div>



        <?php endif; ?>



    </div>







    <!-- ================= แท็บ 2: ประวัติงานเสร็จสิ้น ================= -->



    <div id="content-completed" class="space-y-4 <?php echo ($activeTab === "completed" ? "" : "hidden"); ?>">



        <?php if(count($completedJobs) > 0): foreach($completedJobs as $job): 



            $assignedTime = !empty($job['assignedAt']) ? $job['assignedAt'] : $job['requestDate'];



            $duration = (!empty($assignedTime) && !empty($job['completedAt'])) ? duration_th($assignedTime, $job['completedAt']) : ((!empty($job['requestDate']) && !empty($job['completedAt'])) ? duration_th($job['requestDate'], $job['completedAt']) : '');



        ?>



        <div onclick="openStaffJobDetailModal(<?php echo $job['requestId']; ?>)" class="bg-white p-5 rounded-3xl border-l-4 border-l-emerald-500 border-y border-r border-rose-100/80 shadow-sm hover:shadow-md transition cursor-pointer group">



            <div class="flex flex-col md:flex-row gap-5">



                



                <div class="flex-1 min-w-0">



                    <div class="flex items-center gap-2 mb-2 flex-wrap">



                        <span class="text-xs font-bold px-2.5 py-1 rounded-xl bg-slate-100 text-slate-700 border border-rose-100/80 font-mono">ห้อง <?php echo htmlspecialchars($job['roomNumber']); ?></span>



                        <?php if($job['status'] === 'Pending Approval'): ?>
                            <span class="text-[10px] text-amber-800 font-bold bg-amber-50 px-2.5 py-1 rounded-xl border border-amber-200 flex items-center gap-1">
                                <i data-lucide="clock" class="w-3 h-3 text-amber-600"></i> ส่งงานแล้ว (รอแอดมินอนุมัติ)
                            </span>
                        <?php else: ?>
                            <span class="text-[10px] text-emerald-700 font-bold bg-emerald-50 px-2.5 py-1 rounded-xl border border-emerald-200 flex items-center gap-1">
                                <i data-lucide="check-circle-2" class="w-3 h-3 text-emerald-600"></i> เสร็จสิ้น (อนุมัติแล้ว)
                            </span>
                        <?php endif; ?>



                        <span class="text-[10px] text-slate-400 font-mono">REQ-<?php echo str_pad($job['requestId'], 4, '0', STR_PAD_LEFT); ?></span>







                        <?php if(!empty($job['completedAt'])): ?>



                            <span class="text-[11px] text-emerald-800 font-medium bg-emerald-50 px-2.5 py-0.5 rounded-xl border border-emerald-200 flex items-center gap-1">



                                <i data-lucide="check" class="w-3.5 h-3.5 text-emerald-600"></i>



                                ส่งงาน: <b><?php echo date("d/m/Y H:i", strtotime($job['completedAt'])); ?> น.</b>







                            </span>



                        <?php endif; ?>







                        <?php if($duration): ?>



                            <span class="text-[10px] text-slate-600 font-medium bg-rose-50/30 px-2 py-0.5 rounded-lg border border-rose-100/80">



                                ⏱️ ใช้เวลา: <b><?php echo $duration; ?></b>



                            </span>



                        <?php endif; ?>



                    </div>







                    <h4 class="font-bold text-slate-800 text-base group-hover:text-emerald-700 transition-colors"><?php echo htmlspecialchars($job['category']); ?></h4>



                    <p class="text-xs sm:text-sm text-slate-600 mt-1 line-clamp-2"><?php echo htmlspecialchars($job['description']); ?></p>







                    <div class="mt-2.5 flex flex-wrap items-center gap-3 text-xs text-slate-400">



                        <span><i data-lucide="send" class="w-3 h-3 inline mr-1 text-slate-400"></i>แจ้ง: <?php echo date("d/m/Y H:i", strtotime($job['requestDate'])); ?> น.</span>



                        <span><i data-lucide="wrench" class="w-3 h-3 inline mr-1 text-slate-400"></i>รับงาน: <?php echo date("d/m/Y H:i", strtotime($assignedTime)); ?> น.</span>



                        <span><i data-lucide="user" class="w-3 h-3 inline mr-1 text-slate-400"></i><?php echo htmlspecialchars($job['tenantName'] ?? 'ลูกบ้าน'); ?></span>



                    </div>



                </div>







                <!-- แสดงรูปภาพ 2 ช่อง: รูปก่อนทำ และ รูปหลังทำ ชัดเจน! -->



                <div class="shrink-0 flex items-center gap-2.5 justify-end md:justify-center">



                    



                    <!-- 1. รูปก่อนทำ -->



                    <?php if(!empty($job['requestImage'])): ?>



                        <div class="relative group/bimg cursor-pointer w-14 h-14 rounded-xl overflow-hidden border border-amber-200 shadow-2xs bg-slate-900" onclick="event.stopPropagation(); openImageModal('<?php echo htmlspecialchars($job['requestImage']); ?>', 'รูปก่อนทำ (ปัญหาที่แจ้ง)')" title="คลิกดูรูปก่อนทำ">



                            <img src="<?php echo htmlspecialchars($job['requestImage']); ?>" class="w-full h-full object-cover transition group-hover/bimg:scale-105" alt="ก่อนทำ">



                            <span class="absolute bottom-0 inset-x-0 bg-amber-600/90 text-white text-[9px] font-bold text-center py-0.2">ก่อนทำ</span>



                        </div>



                    <?php else: ?>



                        <div class="w-14 h-14 rounded-xl bg-rose-50/30 border border-dashed border-rose-100/80 flex flex-col items-center justify-center text-slate-300 text-[9px]">



                            <i data-lucide="image-off" class="w-4 h-4 mb-0.5"></i>



                            <span>ไม่มีรูป</span>



                        </div>



                    <?php endif; ?>







                    <i data-lucide="arrow-right" class="w-4 h-4 text-slate-300 hidden sm:block"></i>







                    <!-- 2. รูปหลังทำ -->



                    <?php if(!empty($job['completionImage'])): ?>



                        <div class="relative group/aimg cursor-pointer w-14 h-14 rounded-xl overflow-hidden border border-emerald-300 shadow-2xs bg-slate-900" onclick="event.stopPropagation(); openImageModal('<?php echo htmlspecialchars($job['completionImage']); ?>', 'รูปหลังทำ (ผลงานที่ส่งมอบ)')" title="คลิกดูรูปหลังทำ">



                            <img src="<?php echo htmlspecialchars($job['completionImage']); ?>" class="w-full h-full object-cover transition group-hover/aimg:scale-105" alt="หลังทำ">



                            <span class="absolute bottom-0 inset-x-0 bg-emerald-600/90 text-white text-[9px] font-bold text-center py-0.2">หลังทำ</span>



                        </div>



                    <?php endif; ?>







                    <button type="button" onclick="event.stopPropagation(); openStaffJobDetailModal(<?php echo $job['requestId']; ?>)" class="ml-1 px-3 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs cursor-pointer">



                        <i data-lucide="split-square-vertical" class="w-3.5 h-3.5"></i> เปรียบเทียบรูป



                    </button>



                </div>



            </div>



        </div>



        <?php endforeach; else: ?>



        <div class="text-center py-16 bg-white rounded-3xl border border-rose-100/80 shadow-sm">



            <h3 class="text-base font-bold text-slate-700 font-display">ยังไม่มีประวัติการทำงาน</h3>



        </div>



        <?php endif; ?>



    </div>







  </main>







  <!-- 🌟 MODAL จอใหญ่: ขยายดูรายละเอียดงานแบบเต็ม แบ่ง 2 กรอบชัดเจน (ผู้เช่า VS ช่าง/แม่บ้าน) 🌟 -->



  <div id="staffJobDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-0 sm:p-2 md:p-3 overflow-hidden" onclick="closeStaffJobDetailModal()">



      <div class="bg-white w-full h-full sm:h-[97vh] max-w-none sm:max-w-[98vw] 2xl:max-w-[98vw] rounded-none sm:rounded-3xl shadow-2xl overflow-hidden modal-enter border border-rose-100/80/80 flex flex-col" onclick="event.stopPropagation()">



          



          <!-- Modal Top Header -->



          <div class="px-6 py-4 border-b border-rose-100/60 flex justify-between items-center bg-rose-50/30/90 shrink-0">



              <div class="flex items-center gap-2.5 flex-wrap">



                  <span class="text-xs font-bold px-3 py-1 rounded-xl bg-slate-900 text-white font-mono shadow-2xs">



                      ห้อง <span id="staff_detail_room">-</span>



                  </span>



                  <span id="staff_detail_code" class="text-xs font-mono font-bold text-slate-600 bg-white px-2.5 py-1 rounded-xl border border-rose-100/80 shadow-2xs">



                      REQ-0000



                  </span>



                  <div id="staff_detail_status_badge" class="text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5 shadow-2xs"></div>



              </div>



              <div class="flex items-center gap-2">

                  <button type="button" onclick="closeStaffJobDetailModal()" class="w-9 h-9 rounded-2xl bg-slate-200/70 hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shrink-0 shadow-2xs" title="ปิดหน้าต่าง">

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



                  <div class="rounded-3xl p-5 sm:p-6 border-2 border-blue-200 bg-gradient-to-b from-blue-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">



                      <div>



                          <!-- Header กรอบผู้เช่า -->



                          <div class="flex items-center justify-between pb-3.5 border-b border-blue-200/80 mb-4">



                              <div class="flex items-center gap-2.5">



                                  <div class="w-9 h-9 rounded-2xl bg-rose-500 text-white flex items-center justify-center shrink-0 shadow-xs">



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



                                  <h3 class="text-base sm:text-lg font-bold text-slate-900" id="staff_detail_category">-</h3>



                                  <div class="flex items-center gap-1.5 flex-wrap">



                                      <span id="staff_detail_hours_badge" class="text-xs font-bold px-2.5 py-1 rounded-xl bg-amber-50 text-amber-800 border border-amber-200 font-mono shadow-2xs">⏱️ 2 ชม.</span>



                                      <span id="staff_detail_entry_badge" class="text-xs font-bold px-2.5 py-1 rounded-xl border shadow-2xs"></span>



                                  </div>



                              </div>



                          </div>







                          <!-- ข้อมูลผู้แจ้ง & เบอร์ติดต่อ -->



                          <div class="p-4 bg-white rounded-2xl border border-blue-200/80 shadow-2xs flex items-center justify-between flex-wrap gap-3 mb-4">



                              <div class="flex items-center gap-3">



                                  <div class="w-10 h-10 rounded-2xl bg-rose-50 text-blue-600 flex items-center justify-center shrink-0 shadow-2xs font-bold">



                                      <i data-lucide="user" class="w-5 h-5"></i>



                                  </div>



                                  <div>



                                      <span class="text-[10px] text-slate-400 block font-bold uppercase tracking-wider">ผู้เช่า / ผู้แจ้งเรื่อง</span>



                                      <span id="staff_detail_tenant_name" class="font-bold text-slate-900 text-sm sm:text-base">-</span>



                                  </div>



                              </div>



                              <a id="staff_detail_tenant_phone_btn" href="#" class="hidden inline-flex items-center gap-1.5 text-xs font-bold text-emerald-800 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-3.5 py-2 rounded-xl transition shadow-2xs">



                                  <i data-lucide="phone-call" class="w-3.5 h-3.5 text-emerald-600"></i>



                                  <span class="phone-text">โทรหาลูกบ้าน</span>



                              </a>



                          </div>







                          <!-- รายละเอียดปัญหา -->



                          <div class="bg-white p-4 rounded-2xl border border-rose-100/80/80 shadow-2xs mb-4">



                              <div class="text-[11px] font-bold text-slate-400 mb-1.5 flex items-center gap-1.5">



                                  <i data-lucide="align-left" class="w-3.5 h-3.5 text-blue-600"></i> รายละเอียดปัญหาที่แจ้ง:



                              </div>



                              <p class="text-xs sm:text-sm text-slate-700 whitespace-pre-line leading-relaxed font-normal" id="staff_detail_desc">-</p>



                          </div>







                          <!-- นัดหมายวันเวลาเข้าห้อง -->



                          <div id="staff_detail_appointment" class="hidden p-3.5 bg-indigo-50/80 border border-indigo-200/90 rounded-2xl flex items-center gap-3 mb-4 text-xs shadow-2xs">



                              <div class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center shrink-0">



                                  <i data-lucide="calendar-clock" class="w-4 h-4"></i>



                              </div>



                              <div>



                                  <span class="text-[10px] text-indigo-500 font-bold uppercase tracking-wider block">วันและช่วงเวลาที่นัดหมายเข้าห้อง</span>



                                  <span id="staff_detail_pref_text" class="font-bold text-indigo-950 text-xs sm:text-sm"></span>



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



                          <div id="staff_detail_before_img_container" class="min-h-[160px] flex items-center justify-center">



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



                          <div id="staff_detail_staff_box" class="bg-white p-4 rounded-2xl border border-emerald-200/80 shadow-2xs flex items-center justify-between flex-wrap gap-3 mb-4">



                              <div class="flex items-center gap-3 text-slate-700 font-medium">



                                  <div class="w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 shadow-2xs">



                                      <i data-lucide="user-check" class="w-5 h-5"></i>



                                  </div>



                                  <div>



                                      <span class="text-[10px] text-slate-400 block font-bold uppercase tracking-wider">เจ้าหน้าที่ผู้รับผิดชอบ</span>



                                      <span class="font-bold text-slate-900 text-sm sm:text-base" id="staff_detail_staff_name">คุณ<?php echo htmlspecialchars($staffName); ?></span>



                                  </div>



                              </div>



                              <div class="text-xs font-bold px-3 py-1.5 rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-200 font-mono">



                                  <?php echo ($_SESSION['role'] === 'Cleaner' || $_SESSION['role'] === 'Housekeeper') ? 'แม่บ้านประจำหอพัก' : 'ช่างซ่อมบำรุง'; ?>



                              </div>



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



                                      <div class="text-xs font-bold text-slate-800 mt-1 font-mono" id="staff_detail_req_date">-</div>



                                      <div class="text-[10px] text-amber-800 font-semibold mt-0.5" id="staff_detail_req_ago"></div>



                                  </div>







                                  <!-- 2. รับงานเมื่อ -->



                                  <div class="bg-white border border-blue-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">



                                      <span class="text-[11px] font-bold text-blue-700 flex items-center gap-1.5">



                                          <i data-lucide="wrench" class="w-3.5 h-3.5 text-blue-600"></i> 2. รับงานเมื่อ



                                      </span>



                                      <div class="text-xs font-bold text-slate-800 mt-1 font-mono" id="staff_detail_assigned_date">-</div>



                                      <div class="text-[10px] text-blue-800 font-semibold mt-0.5" id="staff_detail_assigned_ago"></div>



                                  </div>







                                  <!-- 3. จบงานเมื่อ -->



                                  <div id="staff_detail_completed_box" class="bg-white border border-rose-100/80 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">



                                      <span class="text-[11px] font-bold text-slate-700 flex items-center gap-1.5">



                                          <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> 3. ส่งงาน/จบงาน



                                      </span>



                                      <div class="text-xs font-bold text-slate-800 mt-1 font-mono" id="staff_detail_completed_date">-</div>



                                      <div class="text-[10px] text-emerald-800 font-semibold mt-0.5" id="staff_detail_duration"></div>



                                  </div>



                              </div>



                          </div>







                          <!-- 📝 รายงาน/บันทึกปัญหาเพิ่มเติมถึงแอดมิน (เห็นเฉพาะแอดมิน) - กล่องพิมพ์และแก้ไขได้ทันทีในหน้าเดียวกัน (แสดงเมื่อสถานะเสร็จสิ้นแล้ว) -->
                          <div id="staff_detail_inline_comment_box" class="bg-amber-50/70 border-2 border-dashed border-amber-300 rounded-2xl p-4 shadow-2xs space-y-2.5 mb-4">
                              <div class="flex items-center justify-between flex-wrap gap-2">
                                  <span class="text-xs font-bold text-amber-950 flex items-center gap-1.5">
                                      <i data-lucide="message-square-warning" class="w-4 h-4 text-amber-600"></i>
                                      รายงาน / บันทึกปัญหาเพิ่มเติมถึงแอดมิน
                                  </span>
                                  <span class="text-[10px] bg-white text-amber-800 font-extrabold px-2.5 py-0.5 rounded-lg border border-amber-200 flex items-center gap-1 shadow-2xs">
                                      <i data-lucide="lock" class="w-3 h-3 text-amber-600"></i> ลับเฉพาะแอดมิน (ลูกบ้านไม่เห็น)
                                  </span>
                              </div>

                              <div class="relative">
                                  <textarea id="staff_detail_comment_textarea" rows="3"
                                      placeholder="แตะที่นี่เพื่อพิมพ์รายงานปัญหาเพิ่มเติมหรือบันทึกช่วยจำถึงแอดมินได้ทันที..."
                                      class="w-full px-4 py-3 text-xs sm:text-sm bg-white border border-amber-200 rounded-xl outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-200/50 text-slate-800 placeholder-slate-400 transition resize-y shadow-2xs leading-relaxed font-sans"></textarea>
                              </div>

                              <div class="flex items-center justify-between text-[11px] text-amber-900 pt-1 flex-wrap gap-2">
                                  <div id="staff_detail_comment_timestamp" class="font-sans text-amber-800 flex items-center gap-1"></div>
                                  <div class="flex items-center gap-2">
                                      <span id="staff_detail_saved_badge" class="hidden text-[11px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-lg border border-emerald-200 flex items-center gap-1">
                                          <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> บันทึกแล้ว!
                                      </span>
                                      <button type="button" id="staff_detail_save_comment_btn" onclick="saveStaffCommentInline()" class="px-4 py-2 bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-700 hover:to-amber-800 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm hover:shadow cursor-pointer">
                                          <i data-lucide="send" class="w-3.5 h-3.5"></i>
                                          <span id="staff_detail_comment_btn_text">บันทึกรายงานปัญหา</span>
                                      </button>
                                  </div>
                              </div>
                          </div>

                      </div>

                      <!-- 📦 ส่วนส่งมอบงาน (แสดงเมื่อสถานะยังไม่เสร็จสิ้น) - จบงานได้ทันทีในหน้าเดียว -->
                      <div id="staff_detail_handover_form_container" class="mt-2">
                          <form action="" method="POST" enctype="multipart/form-data" id="staff_job_complete_form">
                              <input type="hidden" name="request_id" id="staff_detail_form_req_id">

                              <!-- 📷 รูปถ่ายผลงานหลังทำ (ส่งมอบงาน) AFTER สไตล์เดียวกับฝั่งผู้เช่า -->
                              <div class="bg-white border-2 border-dashed border-emerald-300 rounded-2xl p-3.5 shadow-2xs">
                                  <div class="flex items-center justify-between mb-2.5">
                                      <span class="text-xs font-bold text-emerald-900 flex items-center gap-1.5">
                                          <i data-lucide="camera" class="w-4 h-4 text-emerald-600"></i> รูปถ่ายผลงานการซ่อม (หลังทำ) <span class="text-rose-500 font-bold">* (บังคับ)</span>
                                      </span>
                                      <span class="text-[10px] bg-emerald-100 text-emerald-900 font-extrabold px-2.5 py-0.5 rounded-lg border border-emerald-200">AFTER</span>
                                  </div>

                                  <!-- Hidden file input triggered by tapping placeholder or change button -->
                                  <input type="file" name="completion_image" id="staff_modal_completion_file" accept="image/*"
                                      onchange="previewCompletionImage(event)" style="position: absolute; opacity: 0; pointer-events: none; width: 1px; height: 1px;">

                                  <!-- Upload Placeholder (ก่อนเลือกรูป) -->
                                  <div id="staff_completion_upload_placeholder" 
                                      onclick="document.getElementById('staff_modal_completion_file').click()" 
                                      class="w-full aspect-video rounded-2xl bg-emerald-50/50 hover:bg-emerald-100/60 border-2 border-dashed border-emerald-300 transition flex flex-col items-center justify-center text-emerald-700 text-xs font-bold cursor-pointer group shadow-2xs">
                                      <div class="w-12 h-12 rounded-2xl bg-emerald-600 text-white flex items-center justify-center mb-2 shadow-sm group-hover:scale-110 transition">
                                          <i data-lucide="camera" class="w-6 h-6"></i>
                                      </div>
                                      <span class="text-sm font-bold text-slate-800">แตะเพื่อถ่ายรูปหรือเลือกภาพผลงาน</span>
                                      <span class="text-[11px] text-emerald-600 font-normal mt-0.5">แนบภาพผลงานการซ่อม (บังคับ)</span>
                                  </div>

                                  <!-- Preview Image Box (หลังเลือกรูป - สไตล์เหมือนฝั่งผู้เช่า 100%) -->
                                  <div id="staff_completion_img_preview_box" 
                                      onclick="openStaffHandoverPreview()" 
                                      class="hidden relative group cursor-pointer rounded-2xl overflow-hidden border border-emerald-300 shadow-sm bg-slate-950 aspect-video flex items-center justify-center">
                                      <img id="staff_completion_img_preview" src="" alt="หลังทำ" class="w-full h-full object-cover transition duration-300 group-hover:scale-105">
                                      <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end justify-between p-2.5 opacity-90 group-hover:opacity-100 transition pointer-events-none">
                                          <span class="text-xs text-white font-bold flex items-center gap-1">
                                              <i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม
                                          </span>
                                          <button type="button" onclick="event.stopPropagation(); document.getElementById('staff_modal_completion_file').click()" class="pointer-events-auto px-2.5 py-1 bg-black/60 hover:bg-emerald-600 text-white rounded-lg text-xs font-bold transition flex items-center gap-1 shadow cursor-pointer">
                                              <i data-lucide="refresh-cw" class="w-3 h-3"></i> เปลี่ยนรูป
                                          </button>
                                      </div>
                                  </div>
                              </div>

                              <!-- 📝 ความคิดเห็น / บันทึกรายงานเพิ่มเติมของช่างก่อนส่งงาน -->
                              <div class="mt-3 bg-amber-50/70 border border-amber-200/90 rounded-2xl p-3.5 shadow-2xs space-y-2">
                                  <div class="flex items-center justify-between">
                                      <label for="staff_modal_comment_textarea" class="text-xs font-bold text-amber-950 flex items-center gap-1.5">
                                          <i data-lucide="message-square" class="w-4 h-4 text-amber-600"></i>
                                          ความคิดเห็น / บันทึกเพิ่มเติมของช่างก่อนส่งงาน <span class="text-slate-400 font-normal">(ถ้ามี)</span>
                                      </label>
                                      <span class="text-[10px] bg-white text-amber-800 font-semibold px-2 py-0.5 rounded-md border border-amber-200 shadow-2xs">
                                          รายงานช่าง
                                      </span>
                                  </div>
                                  <textarea name="staff_comment" id="staff_modal_comment_textarea" rows="2"
                                      placeholder="พิมพ์ข้อความบันทึก รายละเอียดสิ่งที่ซ่อม หรือหมายเหตุเพิ่มเติมก่อนส่งงาน (เผื่อช่างอยากคอมเมนต์อะไรเพิ่มเติม)..."
                                      class="w-full px-3.5 py-2.5 text-xs sm:text-sm bg-white border border-amber-200 rounded-xl outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200/50 text-slate-800 placeholder-slate-400 transition resize-y shadow-2xs font-sans leading-relaxed"></textarea>
                              </div>

                              <!-- ปุ่มยืนยันการส่งงาน -->
                              <button type="submit" name="complete_job" class="w-full py-3.5 bg-gradient-to-r from-emerald-600 via-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white rounded-2xl text-sm font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-2 cursor-pointer mt-3">
                                  <i data-lucide="check-circle" class="w-5 h-5"></i> ยืนยันการส่งงาน
                              </button>
                          </form>
                      </div>

                      <!-- ✨ รูปหลังทำ (ผลงานที่ช่างส่งมอบ) AFTER (แสดงเมื่อสถานะเสร็จสิ้นแล้ว) -->
                      <div id="staff_detail_after_img_section" class="bg-white border-2 border-dashed border-emerald-300 rounded-2xl p-3.5 shadow-2xs mt-2">
                          <div class="flex items-center justify-between mb-2.5">
                              <span class="text-xs font-bold text-emerald-900 flex items-center gap-1.5">
                                  <i data-lucide="sparkles" class="w-4 h-4 text-emerald-600"></i> รูปผลงานการซ่อม (หลังทำ)
                              </span>
                              <span class="text-[10px] bg-emerald-100 text-emerald-900 font-extrabold px-2.5 py-0.5 rounded-lg border border-emerald-200">AFTER</span>
                          </div>
                          <div id="staff_detail_after_img_container" class="min-h-[160px] flex items-center justify-center">
                              <!-- Dynamic After Image -->
                          </div>
                      </div>



                  </div>







              </div>















          </div>







          <!-- Modal Footer -->



          <div class="px-6 py-4 border-t border-rose-100/60 bg-rose-50/30 flex items-center justify-end gap-3 shrink-0">



              <button type="button" onclick="closeStaffJobDetailModal()" class="px-5 py-2.5 bg-white hover:bg-slate-100 border border-rose-100/80 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer shadow-2xs">



                  ปิดหน้าต่าง



              </button>







              



          </div>







      </div>



  </div>







  <!-- 🌟 MODAL: แบบฟอร์มส่งงาน (บังคับอัปโหลดรูป) 🌟 -->



  



  <!-- 🌟 MODAL: บันทึก/รายงานปัญหาเพิ่มเติมถึงแอดมิน (เห็นเฉพาะแอดมิน) 🌟 -->



  <div id="staffCommentModal" class="hidden fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">



      <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden modal-enter">



          <div class="p-5 border-b border-rose-100/60 flex justify-between items-center bg-rose-50/30">



              <div class="flex items-center gap-2.5">



                  <div class="w-9 h-9 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center shadow-xs">



                      <i data-lucide="message-square-warning" class="w-5 h-5"></i>



                  </div>



                  <div>



                      <h3 class="font-display font-bold text-slate-800 text-base" id="staffCommentModalTitle">รายงานปัญหาเพิ่มเติมถึงแอดมิน</h3>



                      <p class="text-xs text-slate-500">ห้อง <span id="staff_comment_modal_room" class="font-bold text-slate-700">-</span> • <span id="staff_comment_modal_code" class="font-mono font-bold">-</span></p>



                  </div>



              </div>



              <button type="button" onclick="closeStaffCommentModal()" class="text-slate-400 hover:text-rose-500 p-1.5 rounded-lg transition"><i data-lucide="x" class="w-5 h-5"></i></button>



          </div>



          



          <form action="" method="POST" class="p-6 space-y-4">



              <input type="hidden" name="request_id" id="staff_comment_modal_request_id">



              <div>



                  <div class="flex items-center justify-between mb-1.5">



                      <label class="block text-xs font-bold text-slate-700">รายละเอียดปัญหาหรือข้อเสนอแนะเพิ่มเติมจากช่าง/แม่บ้าน</label>



                      <span class="text-[10px] text-amber-800 font-extrabold bg-amber-50 px-2 py-0.5 rounded-lg border border-amber-200">



                          🔒 ลับเฉพาะแอดมิน



                      </span>



                  </div>



                  <textarea name="staff_comment" id="staff_comment_modal_textarea" rows="4" required placeholder="เช่น ท่อด้านในเริ่มผุกร่อน แนะนำให้เปลี่ยนทั้งชุด / ลูกบ้านไม่อยู่ห้อง นัดช่างช่วงเย็นแทน..." class="w-full px-3.5 py-2.5 text-sm bg-rose-50/30 border border-rose-100/80 rounded-xl outline-none focus:border-amber-500 focus:bg-white transition resize-none"></textarea>



                  <div class="text-[11px] text-amber-900 bg-amber-50 p-2.5 rounded-xl border border-amber-200 mt-2 flex items-center gap-2">



                      <i data-lucide="shield-check" class="w-4 h-4 text-amber-600 shrink-0"></i>



                      <span><strong>🔒 ความเป็นส่วนตัว:</strong> ข้อความนี้จะส่งถึงแอดมิน/นิติบุคคลเท่านั้น (ลูกบ้านจะไม่เห็นข้อความนี้)</span>



                  </div>



              </div>



              



              <div class="flex items-center justify-end gap-2.5 pt-2">



                  <button type="button" onclick="closeStaffCommentModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition">ยกเลิก</button>



                  <button type="submit" name="save_staff_comment" class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5 cursor-pointer">



                      <i data-lucide="send" class="w-3.5 h-3.5"></i> บันทึกรายงานปัญหา



                  </button>



              </div>



          </form>



      </div>



  </div>







  <!-- completeModal removed as requested (merged into staffJobDetailModal) -->







  <!-- 🌟 MODAL: ดูรูปภาพ 🌟 -->



  <div id="imagePreviewModal" class="hidden fixed inset-0 z-[60] bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4 modal-enter" onclick="closeImageModal()">



      <div class="relative max-w-4xl w-full flex flex-col items-center justify-center" onclick="event.stopPropagation()">



          <button type="button" onclick="closeImageModal()" class="absolute -top-12 right-0 text-white/70 hover:text-rose-400 p-2 transition flex items-center gap-2 font-bold text-sm">



              ปิดรูปภาพ <i data-lucide="x-circle" class="w-6 h-6"></i>



          </button>



          <div id="imageTitle" class="absolute -top-10 left-0 text-white font-bold text-sm bg-black/50 px-3 py-1 rounded-full backdrop-blur-md"></div>



          <img id="previewImage" src="" alt="Image Preview" class="max-h-[80vh] max-w-full rounded-2xl shadow-2xl object-contain border-2 border-white/10 bg-black/50">



      </div>



  </div>







  <script>



    lucide.createIcons();







    // ซ่อน Alert อัตโนมัติ



    document.addEventListener("DOMContentLoaded", function() {



        const alerts = document.querySelectorAll(".alert-box");



        alerts.forEach(function(alert) {



            setTimeout(function() {



                alert.style.opacity = "0";



                setTimeout(function() { alert.remove(); }, 500);



            }, 3500);



        });



    });







    // ระบบสลับแท็บ



    function switchTab(tabId) {



        document.getElementById('content-active').classList.add('hidden');



        document.getElementById('content-completed').classList.add('hidden');



        document.getElementById('content-' + tabId).classList.remove('hidden');







        // Reset styling



        document.getElementById('tab-active').className = "px-5 py-2.5 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition";



        document.getElementById('tab-completed').className = "px-5 py-2.5 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition";







        // Active styling



        const activeClass = "px-5 py-2.5 text-sm font-bold border-b-2 border-indigo-600 text-indigo-600 flex items-center gap-2 whitespace-nowrap transition";



        document.getElementById('tab-' + tabId).className = activeClass;



    }







    // เปิดฟอร์มส่งงาน



    function openCompleteModal(reqId, roomNum, category) {
        openStaffJobDetailModal(reqId, true);
    }







    // --- ฟังก์ชันบันทึกรายงานปัญหาเพิ่มเติมของช่าง/แม่บ้านแบบ Inline ในหน้าเดียวกัน ---
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function saveStaffCommentInline() {
        const textarea = document.getElementById('staff_detail_comment_textarea');
        if (!textarea) return;
        const comment = textarea.value.trim();
        const job = window.currentActiveStaffJob;
        if (!job || !job.requestId) {
            alert('ไม่พบข้อมูลงาน');
            return;
        }

        const btn = document.getElementById('staff_detail_save_comment_btn');
        const btnText = document.getElementById('staff_detail_comment_btn_text');
        const savedBadge = document.getElementById('staff_detail_saved_badge');
        const timeEl = document.getElementById('staff_detail_comment_timestamp');

        if (btn) btn.disabled = true;
        if (btnText) btnText.textContent = 'กำลังบันทึก...';

        const formData = new FormData();
        formData.append('action', 'save_staff_comment');
        formData.append('request_id', job.requestId);
        formData.append('staff_comment', comment);

        fetch('staff_dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (btnText) btnText.textContent = 'บันทึกการแก้ไข';

            if (data.success) {
                job.staffComment = comment;
                job.staffCommentedAt = data.staffCommentedAt;
                if (window.staffJobs && window.staffJobs[job.requestId]) {
                    window.staffJobs[job.requestId].staffComment = comment;
                    window.staffJobs[job.requestId].staffCommentedAt = data.staffCommentedAt;
                }

                if (timeEl) {
                    timeEl.innerHTML = `<i data-lucide="clock" class="w-3.5 h-3.5 text-amber-600"></i> บันทึกเมื่อ: ${data.staffCommentedAt} น.`;
                }

                // 🌟 อัปเดตการ์ดงานของช่างบนแดชบอร์ดทันทีแบบเรียลไทม์ 🌟
                const cardSec = document.getElementById('staff_card_comment_section_' + job.requestId);
                if (cardSec) {
                    if (comment) {
                        cardSec.innerHTML = `
                            <div class="mt-3 p-3 bg-amber-50/90 border border-amber-200/90 rounded-2xl flex items-start justify-between gap-3 text-xs">
                                <div class="flex items-start gap-2 text-amber-950">
                                    <i data-lucide="message-square-warning" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5"></i>
                                    <div>
                                        <span class="font-bold text-amber-900 block">รายงานปัญหาถึงแอดมิน:</span>
                                        <span class="text-slate-700 whitespace-pre-line">${escapeHtml(comment)}</span>
                                        <span class="text-[10px] text-amber-800/80 block mt-0.5 font-mono">บันทึกเมื่อ: ${data.staffCommentedAt} น.</span>
                                    </div>
                                </div>
                                <button type="button" onclick="event.stopPropagation(); openStaffCommentModal(${job.requestId}, '${escapeHtml(job.roomNumber || '')}', 'REQ-${String(job.requestId).padStart(4, '0')}', '${escapeHtml(comment)}')" class="text-[11px] font-bold text-amber-800 hover:text-amber-950 underline shrink-0 cursor-pointer">
                                    แก้ไข
                                </button>
                            </div>
                        `;
                    } else {
                        cardSec.innerHTML = '';
                    }
                }
                const actionText = document.getElementById('staff_card_action_text_' + job.requestId);
                if (actionText) {
                    actionText.textContent = comment ? 'แก้ไขรายงานปัญหา' : 'รายงานปัญหาถึงแอดมิน';
                }
                if (savedBadge) {
                    savedBadge.classList.remove('hidden');
                    setTimeout(() => {
                        savedBadge.classList.add('hidden');
                    }, 3500);
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
                        title: 'บันทึกรายงานปัญหาเรียบร้อยแล้ว'
                    });
                }
            } else {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: data.message || 'ไม่สามารถบันทึกข้อมูลได้',
                        confirmButtonColor: '#d97706'
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

    // เมื่อกดปุ่มรายงานปัญหาจากการ์ดภายนอก ให้เปิดหน้ารายละเอียดและโฟกัสไปที่ช่องรายงานทันที
    function openStaffCommentModal(reqId, roomNum, reqCode, currentComment) {
        openStaffJobDetailModal(reqId);
        setTimeout(() => {
            const input = document.getElementById('staff_detail_comment_textarea');
            if (input) {
                input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                input.focus();
            }
        }, 200);
    }

    function closeStaffCommentModal() {
        const modal = document.getElementById('staffCommentModal');
        if (modal) modal.classList.add('hidden');
    }
    function closeCompleteModal() {
        closeStaffJobDetailModal();
    }







    // เปิดดูรูปภาพ



    function openImageModal(url, titleText) {



        document.getElementById('previewImage').src = url;



        document.getElementById('imageTitle').textContent = titleText;



        document.getElementById('imagePreviewModal').classList.remove('hidden');



    }



    



    function closeImageModal() {



        document.getElementById('imagePreviewModal').classList.add('hidden');



        document.getElementById('previewImage').src = ''; 



    }



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