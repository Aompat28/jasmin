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



// 🌟 สร้างคอลัมน์เพิ่มเติมอัตโนมัติหากยังไม่มีใน DB

try { $conn->exec("ALTER TABLE Lease ADD COLUMN moveOutReason TEXT DEFAULT NULL;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN moveOutStatus VARCHAR(50) DEFAULT NULL;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN tenantPaymentSlip VARCHAR(255) DEFAULT NULL;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN settledAt DATETIME DEFAULT NULL;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN refundReceipt VARCHAR(255) DEFAULT NULL;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN damageDetail TEXT DEFAULT NULL;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN finalElectricFee DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN finalWaterFee DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN cleaningFee DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN damageFee DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN breachFee DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}

try { $conn->exec("ALTER TABLE Lease ADD COLUMN netRefundAmount DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}



// ดึงข้อมูลสัญญาเช่าปัจจุบันของผู้เช่า (Active)

$stmtLease = $conn->prepare("

    SELECT l.*, r.roomNumber, r.roomType, r.rentPrice, r.floor, u.fullName AS tenantFullName, u.phone AS tenantPhone

    FROM Lease l 

    JOIN Room r ON l.roomId = r.roomId 

    JOIN User u ON l.tenantId = u.userId

    WHERE l.tenantId = ? AND l.status = 'Active' 

    LIMIT 1

");

$stmtLease->execute([$tenantId]);

$myLease = $stmtLease->fetch(PDO::FETCH_ASSOC);

$myRoomNumber = $myLease ? ($myLease['roomNumber'] ?? '') : '';

if (empty($myRoomNumber)) {

    $stmtHdrR = $conn->prepare("SELECT r.roomNumber FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC LIMIT 1");

    $stmtHdrR->execute([$tenantId]);

    $myRoomNumber = $stmtHdrR->fetchColumn() ?: '';

}



// ดึงข้อมูลสัญญาเช่าที่เคลียร์เรื่องย้ายออกแล้ว (Settled / Inactive)

$stmtSettled = $conn->prepare("

    SELECT l.*, r.roomNumber, r.roomType, r.rentPrice, r.floor, u.fullName AS tenantFullName, u.phone AS tenantPhone

    FROM Lease l 

    JOIN Room r ON l.roomId = r.roomId 

    JOIN User u ON l.tenantId = u.userId

    WHERE l.tenantId = ? AND (l.status = 'Inactive' OR l.settledAt IS NOT NULL)

    ORDER BY l.leaseId DESC 

    LIMIT 1

");

$stmtSettled->execute([$tenantId]);

$settledLease = $stmtSettled->fetch(PDO::FETCH_ASSOC);



// --- 1. ระบบส่งคำขอแจ้งย้ายออก ---

if (isset($_POST['submit_move_out'])) {

    if (!$myLease) {

        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> ไม่สามารถส่งคำขอได้ เนื่องจากคุณยังไม่มีสัญญาเช่าห้องพักที่เปิดใช้งานอยู่</div>";

    } else {

        $moveOutDate = $_POST['moveOutRequestDate'];

        $bankName = trim($_POST['bankName']);

        $bankAccount = trim($_POST['bankAccount']);

        $accountName = trim($_POST['accountName']);

        $moveOutReason = trim($_POST['moveOutReason']);



        // Validation

        if (empty($moveOutDate) || empty($bankName) || empty($bankAccount) || empty($accountName)) {

            $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> กรุณากรอกข้อมูลให้ครบถ้วนก่อนส่งคำขอ</div>";

        } elseif (!preg_match('/^[0-9]{8,15}$/', $bankAccount)) {

            $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เลขที่บัญชีต้องเป็นตัวเลข 8 - 15 หลักเท่านั้น</div>";

        } else {

            try {

                // อัปโหลดไฟล์สลิป (หากผู้เช่าเลือกแนบมาพร้อมคำขอ)

                $uploadedSlip = null;

                $initialMoveOutStatus = 'Pending';

                if (isset($_FILES['move_out_slip']) && $_FILES['move_out_slip']['error'] === UPLOAD_ERR_OK) {

                    $fileTmp = $_FILES['move_out_slip']['tmp_name'];

                    $fileName = $_FILES['move_out_slip']['name'];

                    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

                    if (in_array($fileExt, $allowedExts)) {

                        $uploadDir = 'uploads/';

                        if (!is_dir($uploadDir)) {

                            mkdir($uploadDir, 0777, true);

                        }

                        $targetFile = $uploadDir . 'tenant_slip_' . $myLease['leaseId'] . '_' . time() . '.' . $fileExt;

                        if (move_uploaded_file($fileTmp, $targetFile)) {

                            $uploadedSlip = $targetFile;

                            $initialMoveOutStatus = 'Slip_Uploaded';

                        }

                    }

                }



                $stmtUpdate = $conn->prepare("

                    UPDATE Lease 

                    SET moveOutRequestDate = ?, bankName = ?, bankAccount = ?, accountName = ?, moveOutReason = ?, moveOutStatus = ?, tenantPaymentSlip = COALESCE(?, tenantPaymentSlip)

                    WHERE leaseId = ? AND tenantId = ?

                ");

                $stmtUpdate->execute([$moveOutDate, $bankName, $bankAccount, $accountName, $moveOutReason, $initialMoveOutStatus, $uploadedSlip, $myLease['leaseId'], $tenantId]);

                

                // แจ้งเตือนในตาราง notification สำหรับ Admin

                try {

                    $adminStmt = $conn->query("SELECT userId FROM User WHERE role = 'Admin' LIMIT 1");

                    $adminId = $adminStmt->fetchColumn();

                    if ($adminId) {

                        $notifyMsg = "ลูกบ้านห้อง {$myLease['roomNumber']} ({$myLease['tenantFullName']}) ได้ส่งคำขอแจ้งย้ายออก ในวันที่ " . date("d/m/Y", strtotime($moveOutDate));

                        $stmtNoti = $conn->prepare("INSERT INTO notification (userId, title, message, notificationType) VALUES (?, 'คำขอแจ้งย้ายออกใหม่', ?, 'move_out')");

                        $stmtNoti->execute([$adminId, $notifyMsg]);

                    }

                } catch(PDOException $e) {}



                $message = "<div class='p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5'></i> ส่งคำขอแจ้งย้ายออกเรียบร้อยแล้ว! นิติบุคคลจะรับเรื่องและติดต่อประสานงานตรวจห้องพักตามนัดหมาย</div>";

                

                // ดึงข้อมูลสัญญาอัปเดตล่าสุด

                $stmtLease->execute([$tenantId]);

                $myLease = $stmtLease->fetch(PDO::FETCH_ASSOC);

$myRoomNumber = $myLease ? ($myLease['roomNumber'] ?? '') : '';

if (empty($myRoomNumber)) {

    $stmtHdrR = $conn->prepare("SELECT r.roomNumber FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC LIMIT 1");

    $stmtHdrR->execute([$tenantId]);

    $myRoomNumber = $stmtHdrR->fetchColumn() ?: '';

}

            } catch(PDOException $e) {

                $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . htmlspecialchars($e->getMessage()) . "</div>";

            }

        }

    }

}



// --- 2. ระบบยกเลิกคำขอแจ้งย้ายออก (เคลียร์ข้อมูลจริงทั้งฝั่งผู้เช่าและแอดมิน) ---

if (isset($_POST['cancel_move_out'])) {

    if (!$myLease) {

        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2 alert-box'><i data-lucide='alert-circle' class='w-5 h-5'></i> ไม่พบสัญญาเช่าที่เปิดใช้งาน</div>";

    } else {

        try {

            // ลบไฟล์สลิปหลักฐานการโอนเงิน (หากมี)

            if (!empty($myLease['tenantPaymentSlip']) && file_exists($myLease['tenantPaymentSlip'])) {

                @unlink($myLease['tenantPaymentSlip']);

            }



            // เคลียร์ข้อมูลการขอย้ายออกใน Lease ทั้งหมดให้กลับสู่ภาวะสัญญาเช่าปกติ

            $stmtCancel = $conn->prepare("

                UPDATE Lease 

                SET moveOutRequestDate = NULL, 

                    bankName = NULL, 

                    bankAccount = NULL, 

                    accountName = NULL, 

                    moveOutReason = NULL, 

                    moveOutStatus = NULL, 

                    tenantPaymentSlip = NULL,

                    settledAt = NULL,

                    refundReceipt = NULL,

                    damageDetail = NULL,

                    finalElectricFee = 0,

                    finalWaterFee = 0,

                    cleaningFee = 0,

                    damageFee = 0,

                    breachFee = 0,

                    netRefundAmount = 0

                WHERE leaseId = ? AND tenantId = ?

            ");

            $stmtCancel->execute([$myLease['leaseId'], $tenantId]);



            // ลบการแจ้งเตือนคำขอย้ายออกเดิมที่ค้างอยู่ของห้องนี้ เพื่อไม่ให้แอดมินเห็น

            try {

                $stmtDelNoti = $conn->prepare("

                    DELETE FROM notification 

                    WHERE notificationType IN ('move_out', 'move_out_slip', 'move_out_settled') 

                    AND (message LIKE ? OR message LIKE ?)

                ");

                $searchRoom = "%ห้อง " . $myLease['roomNumber'] . "%";

                $searchName = "%" . $myLease['tenantFullName'] . "%";

                $stmtDelNoti->execute([$searchRoom, $searchName]);



                // แจ้งเตือนแอดมินว่าลูกบ้านได้ยกเลิกคำขอแจ้งย้ายออกแล้ว

                $adminStmt = $conn->query("SELECT userId FROM User WHERE role = 'Admin' LIMIT 1");

                $adminId = $adminStmt->fetchColumn();

                if ($adminId) {

                    $notifyMsg = "ลูกบ้านห้อง {$myLease['roomNumber']} ({$myLease['tenantFullName']}) ได้ยกเลิกคำขอแจ้งย้ายออกแล้ว สถานะห้องพักกลับสู่ภาวะปกติ";

                    $stmtNoti = $conn->prepare("INSERT INTO notification (userId, title, message, notificationType) VALUES (?, 'ลูกบ้านยกเลิกคำขอย้ายออกแล้ว', ?, 'move_out_cancelled')");

                    $stmtNoti->execute([$adminId, $notifyMsg]);

                }

            } catch(PDOException $e) {}



            header("Location: tenant_move_out.php?cancelled=1");

            exit();

        } catch(PDOException $e) {

            $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2 alert-box'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาดในการยกเลิกคำขอ: " . htmlspecialchars($e->getMessage()) . "</div>";

        }

    }

}



// --- 3. ระบบแนบสลิปการโอนเงินคืนห้องของผู้เช่า ---

if (isset($_POST['upload_tenant_slip'])) {

    $targetLeaseId = intval($_POST['lease_id']);

    if (isset($_FILES['tenantPaymentSlip']) && $_FILES['tenantPaymentSlip']['error'] === UPLOAD_ERR_OK) {

        $fileTmp = $_FILES['tenantPaymentSlip']['tmp_name'];

        $fileName = $_FILES['tenantPaymentSlip']['name'];

        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];



        if (in_array($fileExt, $allowedExts)) {

            $newFileName = 'tenant_slip_' . $targetLeaseId . '_' . time() . '.' . $fileExt;

            $uploadDir = 'uploads/';

            if (!is_dir($uploadDir)) {

                mkdir($uploadDir, 0777, true);

            }

            $targetFile = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmp, $targetFile)) {

                $stmtUpload = $conn->prepare("UPDATE Lease SET tenantPaymentSlip = ?, moveOutStatus = 'Slip_Uploaded' WHERE leaseId = ? AND tenantId = ?");

                $stmtUpload->execute([$targetFile, $targetLeaseId, $tenantId]);



                // แจ้งเตือนแอดมิน

                try {

                    $adminStmt = $conn->query("SELECT userId FROM User WHERE role = 'Admin' LIMIT 1");

                    $adminId = $adminStmt->fetchColumn();

                    if ($adminId) {

                        $notifyMsg = "ลูกบ้านได้แนบสลิปโอนเงินส่วนต่างคืนห้องพักเรียบร้อยแล้ว กรุณาเข้าตรวจสอบ";

                        $stmtNoti = $conn->prepare("INSERT INTO notification (userId, title, message, notificationType) VALUES (?, 'ลูกบ้านแนบสลิปคืนห้องแล้ว', ?, 'move_out_slip')");

                        $stmtNoti->execute([$adminId, $notifyMsg]);

                    }

                } catch(PDOException $e) {}



                $message = "<div class='p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> แนบสลิปหลักฐานการโอนเงินเรียบร้อยแล้ว! รอนิติบุคคลตรวจสอบและยืนยันการคืนห้องพัก</div>";

                

                // Refresh data

                $stmtLease->execute([$tenantId]);

                $myLease = $stmtLease->fetch(PDO::FETCH_ASSOC);

$myRoomNumber = $myLease ? ($myLease['roomNumber'] ?? '') : '';

if (empty($myRoomNumber)) {

    $stmtHdrR = $conn->prepare("SELECT r.roomNumber FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC LIMIT 1");

    $stmtHdrR->execute([$tenantId]);

    $myRoomNumber = $stmtHdrR->fetchColumn() ?: '';

}



                $stmtSettled->execute([$tenantId]);

                $settledLease = $stmtSettled->fetch(PDO::FETCH_ASSOC);

            }

        } else {

            $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> ไฟล์ที่แนบต้องเป็นรูปภาพ (JPG, PNG) หรือ PDF เท่านั้น</div>";

        }

    } else {

        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> กรุณาแนบไฟล์สลิปหลักฐานการโอนเงิน</div>";

    }

}



// คำนวณวันที่แนะนำ (30 วันนับจากวันนี้)

$defaultMoveOutDate = date('Y-m-d', strtotime('+30 days'));



// ตรวจสอบข้อความแจ้งเตือนจากการยกเลิกคำขอ

if (isset($_GET['cancelled']) && $_GET['cancelled'] === '1') {

    $message = "

    <div class='p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-2xl shadow-sm flex items-center justify-between gap-3 alert-box'>

        <div class='flex items-center gap-3'>

            <div class='w-9 h-9 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600 shrink-0'>

                <i data-lucide='check-circle-2' class='w-5 h-5'></i>

            </div>

            <div>

                <strong class='font-bold text-sm block text-emerald-900'>ยกเลิกคำขอแจ้งย้ายออกเรียบร้อยแล้ว</strong>

                <span class='text-xs text-emerald-700'>ระบบได้ยกเลิกคำขอแจ้งย้ายออกเรียบร้อยแล้ว สถานะห้องพักกลับสู่สัญญาเช่าปกติ และข้อมูลคำขอย้ายออกจะไม่แสดงในระบบทั้งฝั่งผู้เช่าและแอดมิน</span>

            </div>

        </div>

        <span class='px-3 py-1 bg-emerald-600 text-white rounded-xl text-xs font-bold shrink-0 shadow-xs'>ยกเลิกแล้ว</span>

    </div>

    ";

}



// กำหนดสัญญาสำหรับการแสดงใบสรุปย้ายออก

$clearanceLease = null;

if ($myLease && !empty($myLease['settledAt'])) {

    $clearanceLease = $myLease;

} elseif (!$myLease && $settledLease) {

    $clearanceLease = $settledLease;

}

?>



<!DOCTYPE html>

<html lang="th">

<head>

  <meta charset="UTF-8">

  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>jasmin</title>

  <script src="https://cdn.tailwindcss.com">
    function toggleCustomDropdown(menuId, chevronId, e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById(menuId);
        const chevron = document.getElementById(chevronId);
        // Close others
        ['reasonDropdownMenu', 'bankDropdownMenu'].forEach(id => {
            if (id !== menuId) {
                const el = document.getElementById(id);
                if (el) el.classList.add('hidden');
            }
        });
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

    function selectReasonOption(val, icon, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('reasonSelectInput');
        if (input) input.value = val;
        
        const triggerText = document.getElementById('reasonTriggerText');
        if (triggerText) triggerText.innerText = (val === 'custom') ? 'อื่นๆ (ระบุเอง)' : val;
        
        const triggerIcon = document.getElementById('reasonTriggerIcon');
        if (triggerIcon) triggerIcon.innerText = icon;

        const textEl = document.getElementById('reasonText');
        if (textEl) {
            if (val === 'custom') {
                textEl.value = '';
                textEl.focus();
            } else {
                textEl.value = val;
            }
        }

        const menu = document.getElementById('reasonDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('reasonChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    function selectBankOption(val, icon, badge, badgeClass, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('bankNameInput');
        if (input) input.value = val;

        const triggerText = document.getElementById('bankTriggerText');
        if (triggerText) {
            triggerText.innerText = val;
            triggerText.className = 'text-slate-800 font-bold';
        }

        const triggerBadge = document.getElementById('bankTriggerBadge');
        if (triggerBadge) {
            triggerBadge.innerText = badge;
            triggerBadge.className = 'px-1.5 py-0.5 rounded-md text-[10px] font-bold ' + badgeClass;
        }

        const menu = document.getElementById('bankDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('bankChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#customReasonDropdown')) {
            const rMenu = document.getElementById('reasonDropdownMenu');
            if (rMenu) rMenu.classList.add('hidden');
            const rCh = document.getElementById('reasonChevron');
            if (rCh) rCh.classList.remove('rotate-180');
        }
        if (!e.target.closest('#customBankDropdown')) {
            const bMenu = document.getElementById('bankDropdownMenu');
            if (bMenu) bMenu.classList.add('hidden');
            const bCh = document.getElementById('bankChevron');
            if (bCh) bCh.classList.remove('rotate-180');
        }
    });

</script>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">

  <script src="https://unpkg.com/lucide@latest">
    function toggleCustomDropdown(menuId, chevronId, e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById(menuId);
        const chevron = document.getElementById(chevronId);
        // Close others
        ['reasonDropdownMenu', 'bankDropdownMenu'].forEach(id => {
            if (id !== menuId) {
                const el = document.getElementById(id);
                if (el) el.classList.add('hidden');
            }
        });
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

    function selectReasonOption(val, icon, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('reasonSelectInput');
        if (input) input.value = val;
        
        const triggerText = document.getElementById('reasonTriggerText');
        if (triggerText) triggerText.innerText = (val === 'custom') ? 'อื่นๆ (ระบุเอง)' : val;
        
        const triggerIcon = document.getElementById('reasonTriggerIcon');
        if (triggerIcon) triggerIcon.innerText = icon;

        const textEl = document.getElementById('reasonText');
        if (textEl) {
            if (val === 'custom') {
                textEl.value = '';
                textEl.focus();
            } else {
                textEl.value = val;
            }
        }

        const menu = document.getElementById('reasonDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('reasonChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    function selectBankOption(val, icon, badge, badgeClass, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('bankNameInput');
        if (input) input.value = val;

        const triggerText = document.getElementById('bankTriggerText');
        if (triggerText) {
            triggerText.innerText = val;
            triggerText.className = 'text-slate-800 font-bold';
        }

        const triggerBadge = document.getElementById('bankTriggerBadge');
        if (triggerBadge) {
            triggerBadge.innerText = badge;
            triggerBadge.className = 'px-1.5 py-0.5 rounded-md text-[10px] font-bold ' + badgeClass;
        }

        const menu = document.getElementById('bankDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('bankChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#customReasonDropdown')) {
            const rMenu = document.getElementById('reasonDropdownMenu');
            if (rMenu) rMenu.classList.add('hidden');
            const rCh = document.getElementById('reasonChevron');
            if (rCh) rCh.classList.remove('rotate-180');
        }
        if (!e.target.closest('#customBankDropdown')) {
            const bMenu = document.getElementById('bankDropdownMenu');
            if (bMenu) bMenu.classList.add('hidden');
            const bCh = document.getElementById('bankChevron');
            if (bCh) bCh.classList.remove('rotate-180');
        }
    });

</script>

  <script>

    tailwind.config = {

      theme: { extend: { fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] } } }

    }

  
    function toggleCustomDropdown(menuId, chevronId, e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById(menuId);
        const chevron = document.getElementById(chevronId);
        // Close others
        ['reasonDropdownMenu', 'bankDropdownMenu'].forEach(id => {
            if (id !== menuId) {
                const el = document.getElementById(id);
                if (el) el.classList.add('hidden');
            }
        });
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

    function selectReasonOption(val, icon, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('reasonSelectInput');
        if (input) input.value = val;
        
        const triggerText = document.getElementById('reasonTriggerText');
        if (triggerText) triggerText.innerText = (val === 'custom') ? 'อื่นๆ (ระบุเอง)' : val;
        
        const triggerIcon = document.getElementById('reasonTriggerIcon');
        if (triggerIcon) triggerIcon.innerText = icon;

        const textEl = document.getElementById('reasonText');
        if (textEl) {
            if (val === 'custom') {
                textEl.value = '';
                textEl.focus();
            } else {
                textEl.value = val;
            }
        }

        const menu = document.getElementById('reasonDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('reasonChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    function selectBankOption(val, icon, badge, badgeClass, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('bankNameInput');
        if (input) input.value = val;

        const triggerText = document.getElementById('bankTriggerText');
        if (triggerText) {
            triggerText.innerText = val;
            triggerText.className = 'text-slate-800 font-bold';
        }

        const triggerBadge = document.getElementById('bankTriggerBadge');
        if (triggerBadge) {
            triggerBadge.innerText = badge;
            triggerBadge.className = 'px-1.5 py-0.5 rounded-md text-[10px] font-bold ' + badgeClass;
        }

        const menu = document.getElementById('bankDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('bankChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#customReasonDropdown')) {
            const rMenu = document.getElementById('reasonDropdownMenu');
            if (rMenu) rMenu.classList.add('hidden');
            const rCh = document.getElementById('reasonChevron');
            if (rCh) rCh.classList.remove('rotate-180');
        }
        if (!e.target.closest('#customBankDropdown')) {
            const bMenu = document.getElementById('bankDropdownMenu');
            if (bMenu) bMenu.classList.add('hidden');
            const bCh = document.getElementById('bankChevron');
            if (bCh) bCh.classList.remove('rotate-180');
        }
    });

</script>

  <style>

    body { background-color: #FAF9F6; color: #1e293b; }

    input:not([type="checkbox"]):not([type="radio"]), select, textarea {

        background-color: #FAF9F6; border: 1px solid #e2e8f0; border-radius: 0.75rem;

        padding: 0.625rem 1rem; font-size: 0.875rem; width: 100%; transition: all 0.2s;

    }

    input:focus, select:focus, textarea:focus { background-color: #ffffff; border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }

    label { display: block; font-size: 0.75rem; font-weight: 600; color: #475569; margin-bottom: 0.375rem; }

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
            <i data-lucide="log-out" class="w-5 h-5"></i>
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
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">คำขอยกเลิกสัญญาและย้ายออก</p>
          </div>

        </div>



        <div class="flex items-center space-x-2 sm:space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>

          <!-- ลิงก์โปรไฟล์ของผู้เช่า (ตรงชื่อ) -->

          <a href="profile.php" class="flex items-center gap-2 p-1 sm:p-1.5 hover:bg-rose-50/70 rounded-xl transition border border-transparent hover:border-rose-200 group" title="โปรไฟล์ของฉัน">

            <div class="w-8 h-8 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center font-bold text-xs border border-rose-200 shadow-2xs shrink-0">

              <?php echo $initials; ?>

            </div>

            <div class="hidden sm:block text-left pr-0.5">

              <div class="text-xs font-bold text-slate-800 group-hover:text-rose-600 transition truncate max-w-[120px] sm:max-w-[160px]"><?php echo htmlspecialchars($tenantName); ?></div>

              <div class="text-[10px] text-rose-600 font-semibold truncate flex items-center gap-1">

                <?php if(!empty($myRoomNumber)): ?>

                  <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> ห้อง <?php echo htmlspecialchars($myRoomNumber); ?>

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



  <main class="flex-1 max-w-4xl w-full mx-auto px-4 sm:px-6 py-6 sm:py-8">

    

    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">
      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
          <div class="flex items-center gap-2 flex-wrap mb-2">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
              <i data-lucide="log-out" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>แจ้งย้ายออก</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="banknote" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>ขอคืนห้องพัก & เงินประกัน</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            แจ้งย้ายออก / ขอคืนห้องพัก
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            ยื่นคำขอแจ้งย้ายออกล่วงหน้า พร้อมระบุบัญชีธนาคารสำหรับรับโอนเงินประกันความเสียหายคืน
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



    <!-- Alert Box -->

    <?php echo $message; ?>



    <?php if($clearanceLease): ?>

      <!-- 🌟 แสดงใบสรุปการหักเงินประกันและการย้ายออก (Move-Out Clearance Statement) -->

      <div class="bg-white rounded-3xl border border-emerald-200 shadow-xl overflow-hidden mb-8">

        <!-- Header Banner -->

        <div class="bg-gradient-to-r from-emerald-600 to-teal-700 p-6 text-white flex flex-col sm:flex-row sm:items-center justify-between gap-4">

          <div class="flex items-center gap-3">

            <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur-md flex items-center justify-center shrink-0">

              <i data-lucide="file-check-2" class="w-6 h-6 text-white"></i>

            </div>

            <div>

              <div class="flex items-center gap-2">

                <h2 class="font-display font-bold text-xl">ใบสรุปการหักเงินประกันและการย้ายออก</h2>

                <?php if($clearanceLease['status'] === 'Inactive'): ?>

                  <span class="bg-emerald-400/30 text-white text-xs px-2.5 py-0.5 rounded-full font-medium border border-white/20">ย้ายออกสมบูรณ์</span>

                <?php else: ?>

                  <span class="bg-amber-400/30 text-amber-100 text-xs px-2.5 py-0.5 rounded-full font-medium border border-white/20 animate-pulse">รอตรวจสอบสลิป</span>

                <?php endif; ?>

              </div>

              <p class="text-xs text-emerald-100 mt-0.5">ห้อง <?php echo htmlspecialchars($clearanceLease['roomNumber']); ?> | อนุมัติสรุปยอดเมื่อ: <?php echo !empty($clearanceLease['settledAt']) ? date("d/m/Y H:i น.", strtotime($clearanceLease['settledAt'])) : date("d/m/Y", strtotime($clearanceLease['updatedAt'])); ?></p>

            </div>

          </div>

          <button onclick="window.print()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-white/10 hover:bg-white/20 text-white text-xs font-semibold rounded-xl border border-white/30 backdrop-blur-md transition">

            <i data-lucide="printer" class="w-4 h-4"></i> พิมพ์ใบสรุป

          </button>

        </div>



        <div class="p-6 sm:p-8 space-y-6">

          <!-- ข้อมูลเบื้องต้น -->

          <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-sm bg-rose-50/30 p-4 rounded-2xl border border-rose-100/80">

            <div>

              <span class="text-xs text-slate-500 block">ผู้เช่า:</span>

              <strong class="text-slate-800 font-semibold"><?php echo htmlspecialchars($clearanceLease['tenantFullName']); ?></strong>

            </div>

            <div>

              <span class="text-xs text-slate-500 block">วันเริ่มสัญญาเดิม:</span>

              <strong class="text-slate-800 font-mono"><?php echo date("d/m/Y", strtotime($clearanceLease['startDate'])); ?></strong>

            </div>

            <div>

              <span class="text-xs text-slate-500 block">สัญญาเดิมสิ้นสุด:</span>

              <strong class="text-slate-800 font-mono"><?php echo date("d/m/Y", strtotime($clearanceLease['endDate'])); ?></strong>

            </div>

            <div>

              <span class="text-xs text-slate-500 block">วันที่ย้ายออก:</span>

              <strong class="text-slate-800 font-mono"><?php echo !empty($clearanceLease['moveOutRequestDate']) ? date("d/m/Y", strtotime($clearanceLease['moveOutRequestDate'])) : '-'; ?></strong>

            </div>

            <?php if(!empty($clearanceLease['leaseImage'])): ?>

              <div class="sm:col-span-4 border-t border-rose-100/80 pt-3 flex items-center justify-between">

                <span class="text-xs text-indigo-900 flex items-center gap-1.5 font-bold">

                  <i data-lucide="file-check-2" class="w-4 h-4 text-indigo-600"></i> สัญญาเช่าเดิมของผู้เช่า

                </span>

                <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($clearanceLease['leaseImage'], ENT_QUOTES); ?>')" class="px-3.5 py-1.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-bold text-xs shadow-xs transition flex items-center gap-1.5 cursor-pointer">

                  <i data-lucide="file-text" class="w-3.5 h-3.5"></i> เปิดดูเอกสารสัญญาเช่าเดิม 📄

                </button>

              </div>

            <?php endif; ?>

          </div>



          <!-- รายการหักเงินประกัน -->

          <div>

            <h3 class="font-bold text-slate-800 text-sm mb-3 flex items-center gap-2">

              <i data-lucide="list-ordered" class="w-4 h-4 text-rose-600"></i> รายละเอียดการหักค่าใช้จ่าย & เงินประกันความเสียหาย

            </h3>

            <div class="border border-rose-100/80 rounded-2xl overflow-hidden divide-y divide-slate-100 text-sm">

              

              <!-- เงินประกันเริ่มต้น -->

              <div class="flex justify-between items-center p-4 bg-rose-50/30/80">

                <span class="font-bold text-slate-700">เงินประกันความเสียหายแรกเข้า (Security Deposit)</span>

                <span class="font-bold font-mono text-rose-600 text-base">฿<?php echo number_format($clearanceLease['depositAmount'], 2); ?></span>

              </div>



              <!-- หักค่าไฟ -->

              <div class="flex justify-between items-center p-3.5 pl-6">

                <span class="text-slate-600 flex items-center gap-2">

                  <i data-lucide="zap" class="w-4 h-4 text-amber-500"></i> ค่าไฟฟ้างวดสุดท้าย

                </span>

                <span class="font-mono text-rose-600 font-medium">- ฿<?php echo number_format($clearanceLease['finalElectricFee'] ?? 0, 2); ?></span>

              </div>



              <!-- หักค่าน้ำ -->

              <div class="flex justify-between items-center p-3.5 pl-6">

                <span class="text-slate-600 flex items-center gap-2">

                  <i data-lucide="droplet" class="w-4 h-4 text-blue-500"></i> ค่าน้ำประปางวดสุดท้าย

                </span>

                <span class="font-mono text-rose-600 font-medium">- ฿<?php echo number_format($clearanceLease['finalWaterFee'] ?? 0, 2); ?></span>

              </div>



              <!-- หักค่าทำความสะอาด -->

              <div class="flex justify-between items-center p-3.5 pl-6">

                <span class="text-slate-600 flex items-center gap-2">

                  <i data-lucide="sparkles" class="w-4 h-4 text-purple-500"></i> ค่าทำความสะอาด / ล้างแอร์

                </span>

                <span class="font-mono text-rose-600 font-medium">- ฿<?php echo number_format($clearanceLease['cleaningFee'] ?? 0, 2); ?></span>

              </div>



              <!-- ค่าปรับอยู่ไม่ครบกำหนด (ถ้ามี) -->

              <?php if(($clearanceLease['breachFee'] ?? 0) > 0): ?>

              <div class="flex justify-between items-center p-3.5 pl-6 bg-rose-50/50">

                <span class="text-rose-700 flex items-center gap-2 font-medium">

                  <i data-lucide="alert-triangle" class="w-4 h-4 text-rose-500"></i> ค่าปรับผิดสัญญา / อยู่ไม่ครบกำหนดสัญญา

                </span>

                <span class="font-mono text-rose-700 font-bold">- ฿<?php echo number_format($clearanceLease['breachFee'], 2); ?></span>

              </div>

              <?php endif; ?>



              <!-- ค่าความเสียหายห้องพัก (ถ้ามี) -->

              <?php if(($clearanceLease['damageFee'] ?? 0) > 0): ?>

              <div class="p-3.5 pl-6 bg-rose-50/50 space-y-1">

                <div class="flex justify-between items-center">

                  <span class="text-rose-700 flex items-center gap-2 font-medium">

                    <i data-lucide="wrench" class="w-4 h-4 text-rose-500"></i> ค่าซ่อมแซมความเสียหายของห้องพัก

                  </span>

                  <span class="font-mono text-rose-700 font-bold">- ฿<?php echo number_format($clearanceLease['damageFee'], 2); ?></span>

                </div>

                <?php if(!empty($clearanceLease['damageDetail'])): ?>

                  <p class="text-xs text-rose-600 bg-rose-100/60 p-2 rounded-lg mt-1">

                    📝 <b>รายละเอียด:</b> <?php echo htmlspecialchars($clearanceLease['damageDetail']); ?>

                  </p>

                <?php endif; ?>

              </div>

              <?php endif; ?>



              <!-- ยอดสุทธิ -->

              <?php 

                $netRefund = floatval($clearanceLease['netRefundAmount'] ?? 0);

              ?>

              <div class="flex justify-between items-center p-4 bg-slate-100">

                <div>

                  <span class="font-bold text-slate-800 text-base">

                    <?php echo ($netRefund >= 0) ? 'ยอดเงินประกันสุทธิที่จะได้รับคืน (Net Refund)' : 'ยอดส่วนต่างที่ลูกบ้านต้องชำระเพิ่ม'; ?>

                  </span>

                  <span class="text-xs block text-slate-500 mt-0.5">คำนวณจาก เงินประกัน - ค่าใช้จ่ายรวม</span>

                </div>

                <span class="font-display font-bold text-2xl <?php echo ($netRefund >= 0) ? 'text-emerald-600' : 'text-rose-600'; ?>">

                  ฿<?php echo number_format(abs($netRefund), 2); ?>

                </span>

              </div>

            </div>

          </div>



          <!-- บัญชีธนาคารสำหรับรับโอนเงินคืน -->

          <div class="p-4 bg-emerald-50/70 border border-emerald-200 rounded-2xl flex items-start gap-3">

            <i data-lucide="building-2" class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5"></i>

            <div class="text-xs text-emerald-900 space-y-1">

              <h4 class="font-bold text-sm text-emerald-950">ช่องทางรับเงินประกันคืน</h4>

              <p>นิติบุคคลจะดำเนินการโอนเงินจำนวน <b>฿<?php echo number_format(max(0, $netRefund), 2); ?></b> เข้าบัญชีธนาคารของคุณ:</p>

              <div class="font-mono text-slate-800 bg-white/80 p-2.5 rounded-xl border border-emerald-200/80 inline-block font-semibold mt-1">

                🏦 <?php echo htmlspecialchars($clearanceLease['bankName'] ?? 'ไม่ระบุ'); ?> | 

                เลขบัญชี: <?php echo htmlspecialchars($clearanceLease['bankAccount'] ?? '-'); ?> | 

                ชื่อ: <?php echo htmlspecialchars($clearanceLease['accountName'] ?? '-'); ?>

              </div>

              <p class="text-[11px] text-emerald-700 pt-1">* ระยะเวลาโอนเงินคืนเข้าบัญชีภายใน 7 - 15 วันทำการ หากมีข้อสงสัยกรุณาติดต่อนิติบุคคล</p>

            </div>

          </div>



          <!-- 🌟 ใบเสร็จ / หลักฐานการโอนเงินคืนมัดจำจากแอดมิน -->

          <?php if(!empty($clearanceLease['refundReceipt'])): ?>

          <div class="p-4 bg-rose-50/30 border border-rose-100/80 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">

            <div class="flex items-center gap-3">

              <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">

                <i data-lucide="receipt" class="w-5 h-5"></i>

              </div>

              <div>

                <h4 class="font-bold text-slate-800 text-sm">ใบเสร็จ / หลักฐานการโอนเงินประกันคืน</h4>

                <p class="text-xs text-slate-500">แอดมินได้แนบหลักฐานการโอนเงินคืนมัดจำ/ใบเสร็จการคืนห้องเรียบร้อยแล้ว</p>

              </div>

            </div>

            <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($clearanceLease['refundReceipt'], ENT_QUOTES); ?>')" class="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-xs shadow-md transition flex items-center gap-1.5 cursor-pointer justify-center">

              <i data-lucide="file-text" class="w-4 h-4"></i> ดูและดาวน์โหลดใบเสร็จ 📄

            </button>

          </div>

          <?php endif; ?>



          <!-- 🌟 ส่วนแนบสลิปคืนห้องของผู้เช่า (เพื่อให้แอดมินตรวจสอบก่อนเปลี่ยนสถานะเป็นห้องว่าง) -->

          <?php if(empty($clearanceLease['tenantPaymentSlip'])): ?>

            <form action="" method="POST" enctype="multipart/form-data" class="bg-amber-50/70 p-5 rounded-2xl border border-amber-200 space-y-3 shadow-2xs">

              <input type="hidden" name="lease_id" value="<?php echo $clearanceLease['leaseId']; ?>">

              <div class="flex items-center gap-2">

                <i data-lucide="upload-cloud" class="w-5 h-5 text-amber-600"></i>

                <h4 class="font-bold text-sm text-amber-950">แนบสลิปหลักฐานการโอนเงินส่วนต่าง / คืนห้องพัก</h4>

              </div>

              <p class="text-xs text-amber-900 leading-relaxed">

                <?php echo ($netRefund < 0) ? "กรุณาโอนเงินชำระยอดส่วนต่าง <b>฿" . number_format(abs($netRefund), 2) . "</b> เข้าบัญชีหอพักและแนบสลิปด้านล่างเพื่อให้นิติบุคคลตรวจสอบ" : "กรุณาแนบสลิปหลักฐานความเรียบร้อยเพื่อให้นิติบุคคลตรวจสอบและกดยืนยันการย้ายออกสมบูรณ์"; ?>

              </p>

              <input type="file" name="tenantPaymentSlip" accept="image/*,.pdf" required class="w-full text-xs p-2.5 bg-white border border-amber-300 rounded-xl outline-none focus:border-amber-500">

              <button type="submit" name="upload_tenant_slip" class="w-full py-3 bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs rounded-xl shadow-md transition cursor-pointer flex items-center justify-center gap-1.5">

                <i data-lucide="send" class="w-4 h-4"></i> ส่งสลิปให้นิติบุคคลตรวจสอบ (เพื่อเปลี่ยนเป็นห้องว่าง)

              </button>

            </form>

          <?php else: ?>

            <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs">

              <div class="flex items-center gap-3">

                <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">

                  <i data-lucide="check-circle-2" class="w-5 h-5"></i>

                </div>

                <div>

                  <h4 class="font-bold text-emerald-950 text-sm">คุณได้แนบสลิปหลักฐานเรียบร้อยแล้ว</h4>

                  <p class="text-xs text-emerald-800 mt-0.5">

                    <?php echo ($clearanceLease['status'] === 'Inactive') ? '✅ นิติบุคคลตรวจสอบสลิปและยืนยันการย้ายออกสมบูรณ์แล้ว (ห้องว่าง)' : '⏳ รอนิติบุคคลตรวจสอบสลิปและกดยืนยันให้สถานะห้องเป็นห้องว่าง'; ?>

                  </p>

                </div>

              </div>

              <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($clearanceLease['tenantPaymentSlip'], ENT_QUOTES); ?>')" class="px-4 py-2.5 bg-white text-emerald-800 border border-emerald-300 hover:bg-emerald-100 rounded-xl font-bold text-xs transition cursor-pointer flex items-center gap-1.5 justify-center shadow-2xs">

                <i data-lucide="eye" class="w-4 h-4"></i> ดูสลิปที่แนบ 📄

              </button>

            </div>

          <?php endif; ?>



        </div>

      </div>



    <?php elseif(!$myLease): ?>

      <div class="bg-white rounded-3xl p-8 border border-rose-100/80 shadow-sm text-center">

        <i data-lucide="alert-circle" class="w-12 h-12 text-slate-300 mx-auto mb-3"></i>

        <h3 class="font-bold text-slate-700 text-lg">ไม่พบสัญญาเช่าที่เปิดใช้งาน</h3>

        <p class="text-slate-400 text-xs mt-1">คุณยังไม่มีสัญญาเช่า active อยู่ในระบบ จึงไม่สามารถส่งคำขอแจ้งย้ายออกได้</p>

      </div>

    <?php elseif(!empty($myLease['moveOutRequestDate'])): ?>

      <!-- กรณีที่ส่งคำขอแจ้งย้ายออกแล้ว -->

      <div class="bg-white rounded-3xl border border-rose-200 shadow-lg p-6 sm:p-8 space-y-6">

        <div class="p-4 bg-rose-50 border border-rose-200 rounded-2xl flex items-start gap-3">

          <i data-lucide="clock" class="w-6 h-6 text-rose-600 shrink-0 mt-0.5"></i>

          <div>

            <h3 class="font-bold text-rose-900 text-base">คุณได้ส่งคำขอแจ้งย้ายออกเรียบร้อยแล้ว</h3>

            <p class="text-xs text-rose-800 mt-1 leading-relaxed">

              นิติบุคคลได้รับเรื่องคำขอแจ้งย้ายออกของคุณแล้ว ทางหอพักจะดำเนินการเตรียมตรวจเช็คสภาพห้องพักและคำนวณหักค่าใช้จ่ายส่วนต่างในวันที่นัดหมาย

            </p>

          </div>

        </div>



        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm bg-rose-50/30 p-5 rounded-2xl border border-rose-100/80">

          <div>

            <span class="text-xs text-slate-500 block">ห้องพักปัจจุบัน:</span>

            <strong class="text-slate-800 font-bold text-base">ห้อง <?php echo htmlspecialchars($myLease['roomNumber']); ?></strong>

          </div>

          <div>

            <span class="text-xs text-slate-500 block">วันเริ่มสัญญา - สิ้นสุดสัญญาเดิม:</span>

            <strong class="text-slate-800 font-mono text-xs"><?php echo date("d/m/Y", strtotime($myLease['startDate'])); ?> - <?php echo date("d/m/Y", strtotime($myLease['endDate'])); ?></strong>

          </div>

          <div>

            <span class="text-xs text-slate-500 block">วันที่ประสงค์ย้ายออก:</span>

            <strong class="text-rose-600 font-bold text-base font-mono"><?php echo date("d/m/Y", strtotime($myLease['moveOutRequestDate'])); ?></strong>

          </div>

          <div>

            <span class="text-xs text-slate-500 block">เงินประกันความเสียหายเดิม:</span>

            <strong class="text-emerald-700 font-mono font-bold">฿<?php echo number_format($myLease['depositAmount']); ?></strong>

          </div>

          <div>

            <span class="text-xs text-slate-500 block">ธนาคารสำหรับคืนเงินประกัน:</span>

            <strong class="text-slate-800 font-semibold"><?php echo htmlspecialchars($myLease['bankName'] ?? '-'); ?></strong>

          </div>

          <div>

            <span class="text-xs text-slate-500 block">เลขที่บัญชี / ชื่อบัญชี:</span>

            <strong class="text-slate-800 font-mono"><?php echo htmlspecialchars($myLease['bankAccount'] ?? '-'); ?></strong> (<?php echo htmlspecialchars($myLease['accountName'] ?? '-'); ?>)

          </div>

          <?php if(!empty($myLease['leaseImage'])): ?>

            <div class="sm:col-span-2 border-t border-rose-100/80 pt-3 flex items-center justify-between">

              <span class="text-xs text-indigo-900 flex items-center gap-1.5 font-bold">

                <i data-lucide="file-check-2" class="w-4 h-4 text-indigo-600"></i> เอกสารสัญญาเช่าเดิม

              </span>

              <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($myLease['leaseImage'], ENT_QUOTES); ?>')" class="px-3 py-1.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-bold text-xs shadow-xs transition flex items-center gap-1.5 cursor-pointer">

                <i data-lucide="file-text" class="w-3.5 h-3.5"></i> เปิดดูเอกสารสัญญาเช่าเดิม 📄

              </button>

            </div>

          <?php endif; ?>

          <?php if(!empty($myLease['moveOutReason'])): ?>

            <div class="sm:col-span-2 border-t border-rose-100/80 pt-3 mt-1">

              <span class="text-xs text-slate-500 block">เหตุผลการย้ายออก:</span>

              <p class="text-slate-700 text-xs mt-0.5 whitespace-pre-line"><?php echo htmlspecialchars($myLease['moveOutReason']); ?></p>

            </div>

          <?php endif; ?>

        </div>



        <!-- 🌟 กล่องตรวจสอบและอัปโหลดสลิปสำหรับผู้เช่าขณะรอแอดมินดำเนินการ -->

        <div class="bg-rose-50/30 border border-rose-100/80 rounded-2xl p-5 space-y-4">

          <div class="flex items-center justify-between pb-3 border-b border-rose-100/80">

            <div class="flex items-center gap-2">

              <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">

                <i data-lucide="receipt" class="w-4 h-4"></i>

              </div>

              <div>

                <h4 class="font-bold text-slate-800 text-sm">สลิปหลักฐานการโอนเงิน / ค่าส่วนต่าง</h4>

                <p class="text-[11px] text-slate-500">แนบสลิปให้แอดมินตรวจสอบความถูกต้องเพื่อดำเนินการย้ายออก</p>

              </div>

            </div>

            <?php if(!empty($myLease['tenantPaymentSlip'])): ?>

              <span class="px-2.5 py-1 bg-emerald-100 text-emerald-800 border border-emerald-300 rounded-xl text-xs font-bold flex items-center gap-1 shadow-2xs">

                <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> แนบสลิปแล้ว

              </span>

            <?php else: ?>

              <span class="px-2.5 py-1 bg-amber-100 text-amber-800 border border-amber-300 rounded-xl text-xs font-bold flex items-center gap-1">

                <i data-lucide="clock" class="w-3.5 h-3.5 text-amber-500"></i> ยังไม่ได้แนบสลิป

              </span>

            <?php endif; ?>

          </div>



          <?php if(!empty($myLease['tenantPaymentSlip'])): ?>

            <div class="p-3.5 bg-white border border-emerald-200 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs">

              <div class="text-xs text-emerald-900 flex items-center gap-2">

                <i data-lucide="file-check" class="w-4 h-4 text-emerald-600 shrink-0"></i>

                <span>คุณได้แนบสลิปหลักฐานเรียบร้อยแล้ว แอดมินสามารถเปิดดูและตรวจสอบได้ทันที</span>

              </div>

              <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($myLease['tenantPaymentSlip'], ENT_QUOTES); ?>')" class="shrink-0 px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-xs transition flex items-center justify-center gap-1.5 shadow-xs cursor-pointer">

                <i data-lucide="eye" class="w-3.5 h-3.5"></i> ดูสลิปที่แนบ 📄

              </button>

            </div>

          <?php endif; ?>



          <form action="" method="POST" enctype="multipart/form-data" class="space-y-2.5 pt-1">

            <input type="hidden" name="lease_id" value="<?php echo $myLease['leaseId']; ?>">

            <label class="block text-xs font-semibold text-slate-700">

              <?php echo !empty($myLease['tenantPaymentSlip']) ? 'ต้องการเปลี่ยนสลิป / อัปโหลดใหม่:' : 'แนบสลิปหลักฐานการโอนเงินคืนห้อง:'; ?>

            </label>

            <div class="flex flex-col sm:flex-row gap-2">

              <input type="file" name="tenantPaymentSlip" accept="image/*,.pdf" required class="flex-1 text-xs p-2.5 bg-white border border-slate-300 rounded-xl outline-none focus:border-emerald-500">

              <button type="submit" name="upload_tenant_slip" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer flex items-center justify-center gap-1.5 shrink-0">

                <i data-lucide="upload" class="w-3.5 h-3.5"></i> <?php echo !empty($myLease['tenantPaymentSlip']) ? 'อัปเดตสลิปใหม่' : 'ส่งสลิปให้แอดมิน'; ?>

              </button>

            </div>

            <span class="text-[11px] text-slate-400 block">* รองรับไฟล์รูปภาพ JPG, PNG, WebP หรือ PDF</span>

          </form>

        </div>



        <form action="" method="POST" id="cancelMoveOutForm" data-confirm="คุณแน่ใจหรือไม่ว่าต้องการยกเลิกคำขอแจ้งย้ายออกนี้? (สถานะห้องจะกลับเป็นปกติ)">

          <input type="hidden" name="cancel_move_out" value="1">

          <button type="submit" name="cancel_move_out" value="1" class="w-full py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-sm rounded-xl transition flex items-center justify-center gap-2 cursor-pointer">

            <i data-lucide="rotate-ccw" class="w-4 h-4"></i> ยกเลิกคำขอแจ้งย้ายออก (กลับสู่สถานะปกติ)

          </button>

        </form>

      </div>



    <?php else: ?>

      <!-- 📜 ข้อมูลสัญญาเช่าปัจจุบัน (สัญญาเดิม) -->

      <div class="bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 text-white rounded-3xl shadow-lg p-6 sm:p-7 mb-6 relative overflow-hidden border border-indigo-900/50">

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-white/10 mb-5">

          <div class="flex items-center gap-3">

            <div class="w-10 h-10 rounded-2xl bg-indigo-500/20 border border-indigo-400/30 flex items-center justify-center text-indigo-300 shrink-0">

              <i data-lucide="file-check-2" class="w-5 h-5"></i>

            </div>

            <div>

              <h2 class="font-display font-bold text-lg text-white flex items-center gap-2">

                ข้อมูลสัญญาเช่าปัจจุบัน (สัญญาเดิม)

              </h2>

              <p class="text-xs text-indigo-200">ห้อง <?php echo htmlspecialchars($myLease['roomNumber']); ?> (<?php echo ($myLease['roomType'] === 'Air') ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม'; ?> ชั้น <?php echo $myLease['floor']; ?>)</p>

            </div>

          </div>

          <span class="px-3 py-1 bg-emerald-500/20 text-emerald-300 border border-emerald-400/30 rounded-full text-xs font-bold inline-flex items-center gap-1.5 self-start sm:self-auto">

            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> สัญญาเช่าเปิดใช้งานอยู่

          </span>

        </div>



        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3.5 text-xs">

          <div class="bg-white/5 backdrop-blur-sm p-3.5 rounded-2xl border border-white/10">

            <span class="text-indigo-200/80 block text-[11px] mb-0.5">วันเริ่มสัญญา</span>

            <strong class="text-white font-mono text-sm sm:text-base"><?php echo date("d/m/Y", strtotime($myLease['startDate'])); ?></strong>

          </div>

          <div class="bg-white/5 backdrop-blur-sm p-3.5 rounded-2xl border border-white/10">

            <span class="text-indigo-200/80 block text-[11px] mb-0.5">วันสิ้นสุดสัญญา</span>

            <strong class="text-amber-300 font-mono text-sm sm:text-base"><?php echo date("d/m/Y", strtotime($myLease['endDate'])); ?></strong>

          </div>

          <div class="bg-white/5 backdrop-blur-sm p-3.5 rounded-2xl border border-white/10">

            <span class="text-indigo-200/80 block text-[11px] mb-0.5">ค่าเช่ารายเดือน</span>

            <strong class="text-white font-mono text-sm sm:text-base">฿<?php echo number_format($myLease['rentPrice']); ?></strong>

          </div>

          <div class="bg-white/5 backdrop-blur-sm p-3.5 rounded-2xl border border-white/10">

            <span class="text-indigo-200/80 block text-[11px] mb-0.5">เงินประกัน (มัดจำ)</span>

            <strong class="text-emerald-300 font-mono text-sm sm:text-base">฿<?php echo number_format($myLease['depositAmount']); ?></strong>

          </div>

        </div>



        <?php if(!empty($myLease['leaseImage'])): ?>

          <div class="mt-4 pt-3.5 border-t border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-3">

            <span class="text-xs text-indigo-200 flex items-center gap-1.5">

              <i data-lucide="shield-check" class="w-4 h-4 text-emerald-400"></i> มีเอกสารสัญญาเช่าฉบับแนบในระบบ

            </span>

            <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($myLease['leaseImage'], ENT_QUOTES); ?>')" class="px-4 py-2 bg-rose-500 hover:bg-indigo-500 text-white rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-sm cursor-pointer self-start sm:self-auto">

              <i data-lucide="file-text" class="w-4 h-4"></i> เปิดดูเอกสารสัญญาเช่าเดิม 📄

            </button>

          </div>

        <?php endif; ?>

      </div>



      <!-- ฟอร์มกรอกแจ้งย้ายออก -->

      <form action="" method="POST" enctype="multipart/form-data" id="moveOutForm" onsubmit="validateMoveOutForm(event);" class="space-y-6">

        

        <!-- 1. วันที่ย้ายออก & เหตุผล -->

        <div class="bg-white rounded-3xl border border-rose-100/80 shadow-sm p-6 sm:p-8">

            <h3 class="font-display font-bold text-slate-800 text-base mb-4 flex items-center gap-2 border-b border-rose-100/60 pb-3">

              <span class="w-6 h-6 rounded-full bg-rose-600 text-white text-xs flex items-center justify-center font-bold">1</span>

              กำหนดวันย้ายออก และ เหตุผล

            </h3>



            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

              <div>

                <label>วันที่ประสงค์จะย้ายออก <span class="text-rose-500">*</span></label>

                <input type="date" name="moveOutRequestDate" id="moveOutDateInput" required value="<?php echo $defaultMoveOutDate; ?>" min="<?php echo date('Y-m-d'); ?>">

                <span class="text-[10px] text-amber-600 font-semibold mt-1 block">

                  * ตามระเบียบหอพัก ควรแจ้งย้ายออกล่วงหน้าอย่างน้อย 30 วัน

                </span>

              </div>



              <div>
                <label class="block text-xs font-bold text-slate-700 mb-2">เหตุผลในการย้ายออก <span class="text-slate-400 font-normal">(ไม่บังคับ)</span></label>
                <input type="hidden" name="moveOutReasonSelect" id="reasonSelectInput" value="ครบกำหนดสัญญาเช่า">
                
                <div class="relative" id="customReasonDropdown">
                  <button type="button" onclick="toggleCustomDropdown('reasonDropdownMenu', 'reasonChevron', event)" id="reasonDropdownTrigger"
                          class="w-full px-4 py-3 bg-white border-2 border-rose-200 hover:border-rose-400 focus:border-rose-500 rounded-2xl flex items-center justify-between text-xs sm:text-sm font-bold text-slate-800 shadow-xs transition cursor-pointer">
                    <span class="flex items-center gap-2 truncate">
                      <span id="reasonTriggerIcon" class="text-rose-500">📋</span>
                      <span id="reasonTriggerText">ครบกำหนดสัญญาเช่า</span>
                    </span>
                    <i data-lucide="chevron-down" id="reasonChevron" class="w-4 h-4 text-rose-400 shrink-0 transition-transform duration-200"></i>
                  </button>

                  <div id="reasonDropdownMenu" class="hidden absolute left-0 right-0 top-full mt-1.5 z-50 bg-white/95 backdrop-blur-xl border border-rose-200/90 rounded-2xl shadow-xl p-1.5 space-y-1">
                    <div onclick="selectReasonOption('ครบกำหนดสัญญาเช่า', '📋', event)" class="reason-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-rose-50/80 transition text-xs font-bold text-slate-800 bg-rose-50/50">
                      <span class="flex items-center gap-2"><span>📋</span><span>ครบกำหนดสัญญาเช่า</span></span>
                      <span class="check-r text-rose-600">✓</span>
                    </div>
                    <div onclick="selectReasonOption('ย้ายสถานที่ทำงาน / ย้ายที่เรียน', '💼', event)" class="reason-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-rose-50/80 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span>💼</span><span>ย้ายสถานที่ทำงาน / ย้ายที่เรียน</span></span>
                      <span class="check-r hidden text-rose-600">✓</span>
                    </div>
                    <div onclick="selectReasonOption('ซื้อบ้านใหม่ / ย้ายไปอยู่คอนโดตัวเอง', '🏡', event)" class="reason-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-rose-50/80 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span>🏡</span><span>ซื้อบ้านใหม่ / ย้ายไปอยู่คอนโดตัวเอง</span></span>
                      <span class="check-r hidden text-rose-600">✓</span>
                    </div>
                    <div onclick="selectReasonOption('กลับต่างจังหวัด', '🚗', event)" class="reason-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-rose-50/80 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span>🚗</span><span>กลับต่างจังหวัด</span></span>
                      <span class="check-r hidden text-rose-600">✓</span>
                    </div>
                    <div onclick="selectReasonOption('custom', '✏️', event)" class="reason-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-rose-50/80 transition text-xs font-bold text-slate-800 border-t border-rose-100/60 pt-2">
                      <span class="flex items-center gap-2 text-rose-600"><span>✏️</span><span>อื่นๆ (ระบุเอง)</span></span>
                      <span class="check-r hidden text-rose-600">✓</span>
                    </div>
                  </div>
                </div>
              </div>



              <div class="sm:col-span-2">

                <textarea name="moveOutReason" id="reasonText" rows="2" placeholder="ระบุเหตุผลในการย้ายออกเพิ่มเติม..." class="w-full">ครบกำหนดสัญญาเช่า</textarea>

              </div>

            </div>

        </div>



        <!-- 2. ข้อมูลบัญชีรับโอนเงินประกันคืน -->

        <div class="bg-white rounded-3xl border border-rose-100/80 shadow-sm p-6 sm:p-8">

            <h3 class="font-display font-bold text-slate-800 text-base mb-4 flex items-center gap-2 border-b border-rose-100/60 pb-3">

              <span class="w-6 h-6 rounded-full bg-rose-500 text-white text-xs flex items-center justify-center font-bold">2</span>

              ข้อมูลบัญชีธนาคารสำหรับรับเงินประกันความเสียหายคืน

            </h3>



            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">

              <div>
                <label class="block text-xs font-bold text-slate-700 mb-2">ธนาคาร <span class="text-rose-500">*</span></label>
                <input type="hidden" name="bankName" id="bankNameInput" required value="">
                
                <div class="relative" id="customBankDropdown">
                  <button type="button" onclick="toggleCustomDropdown('bankDropdownMenu', 'bankChevron', event)" id="bankDropdownTrigger"
                          class="w-full px-4 py-3 bg-white border-2 border-rose-200 hover:border-rose-400 focus:border-rose-500 rounded-2xl flex items-center justify-between text-xs sm:text-sm font-bold text-slate-800 shadow-xs transition cursor-pointer">
                    <span class="flex items-center gap-2 truncate">
                      <span id="bankTriggerBadge" class="w-6 h-6 rounded-lg bg-rose-50 text-rose-500 flex items-center justify-center text-xs">🏦</span>
                      <span id="bankTriggerText" class="text-slate-400">-- เลือกธนาคาร --</span>
                    </span>
                    <i data-lucide="chevron-down" id="bankChevron" class="w-4 h-4 text-rose-400 shrink-0 transition-transform duration-200"></i>
                  </button>

                  <div id="bankDropdownMenu" class="hidden absolute left-0 right-0 top-full mt-1.5 z-50 bg-white/95 backdrop-blur-xl border border-rose-200/90 rounded-2xl shadow-xl p-1.5 space-y-1 max-h-60 overflow-y-auto">
                    <div onclick="selectBankOption('ธนาคารกสิกรไทย (KBANK)', '🟢', 'KBANK', 'bg-emerald-50 text-emerald-700 border-emerald-200', event)" class="bank-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-emerald-50/60 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span class="px-1.5 py-0.5 rounded-md text-[10px] bg-emerald-100 text-emerald-800 font-bold">KBANK</span><span>ธนาคารกสิกรไทย</span></span>
                      <span class="check-b hidden text-emerald-600">✓</span>
                    </div>
                    <div onclick="selectBankOption('ธนาคารไทยพาณิชย์ (SCB)', '🟣', 'SCB', 'bg-purple-50 text-purple-700 border-purple-200', event)" class="bank-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-purple-50/60 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span class="px-1.5 py-0.5 rounded-md text-[10px] bg-purple-100 text-purple-800 font-bold">SCB</span><span>ธนาคารไทยพาณิชย์</span></span>
                      <span class="check-b hidden text-purple-600">✓</span>
                    </div>
                    <div onclick="selectBankOption('ธนาคารกรุงเทพ (BBL)', '🔵', 'BBL', 'bg-blue-50 text-blue-700 border-blue-200', event)" class="bank-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-blue-50/60 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span class="px-1.5 py-0.5 rounded-md text-[10px] bg-blue-100 text-blue-800 font-bold">BBL</span><span>ธนาคารกรุงเทพ</span></span>
                      <span class="check-b hidden text-blue-600">✓</span>
                    </div>
                    <div onclick="selectBankOption('ธนาคารกรุงไทย (KTB)', '🔷', 'KTB', 'bg-sky-50 text-sky-700 border-sky-200', event)" class="bank-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-sky-50/60 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span class="px-1.5 py-0.5 rounded-md text-[10px] bg-sky-100 text-sky-800 font-bold">KTB</span><span>ธนาคารกรุงไทย</span></span>
                      <span class="check-b hidden text-sky-600">✓</span>
                    </div>
                    <div onclick="selectBankOption('ธนาคารกรุงศรีอยุธยา (BAY)', '🟡', 'BAY', 'bg-amber-50 text-amber-700 border-amber-200', event)" class="bank-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-amber-50/60 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span class="px-1.5 py-0.5 rounded-md text-[10px] bg-amber-100 text-amber-800 font-bold">BAY</span><span>ธนาคารกรุงศรี</span></span>
                      <span class="check-b hidden text-amber-600">✓</span>
                    </div>
                    <div onclick="selectBankOption('ธนาคารออมสิน (GSB)', '🌸', 'GSB', 'bg-pink-50 text-pink-700 border-pink-200', event)" class="bank-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-pink-50/60 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span class="px-1.5 py-0.5 rounded-md text-[10px] bg-pink-100 text-pink-800 font-bold">GSB</span><span>ธนาคารออมสิน</span></span>
                      <span class="check-b hidden text-pink-600">✓</span>
                    </div>
                    <div onclick="selectBankOption('ธนาคารอาคารสงเคราะห์ (GHB)', '🟠', 'GHB', 'bg-orange-50 text-orange-700 border-orange-200', event)" class="bank-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-orange-50/60 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span class="px-1.5 py-0.5 rounded-md text-[10px] bg-orange-100 text-orange-800 font-bold">GHB</span><span>ธนาคารอาคารสงเคราะห์</span></span>
                      <span class="check-b hidden text-orange-600">✓</span>
                    </div>
                    <div onclick="selectBankOption('ธนาคาร ธ.ก.ส.', '🌾', 'BAAC', 'bg-emerald-50 text-emerald-700 border-emerald-200', event)" class="bank-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-emerald-50/60 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span class="px-1.5 py-0.5 rounded-md text-[10px] bg-emerald-100 text-emerald-800 font-bold">ธ.ก.ส.</span><span>ธนาคาร ธ.ก.ส.</span></span>
                      <span class="check-b hidden text-emerald-600">✓</span>
                    </div>
                    <div onclick="selectBankOption('พร้อมเพย์ (PromptPay)', '⚡', 'PromptPay', 'bg-blue-50 text-blue-700 border-blue-200', event)" class="bank-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-blue-50/60 transition text-xs font-bold text-slate-800">
                      <span class="flex items-center gap-2"><span class="px-1.5 py-0.5 rounded-md text-[10px] bg-indigo-100 text-indigo-800 font-bold">PromptPay</span><span>พร้อมเพย์</span></span>
                      <span class="check-b hidden text-indigo-600">✓</span>
                    </div>
                    <div onclick="selectBankOption('อื่นๆ', '🏦', 'OTHER', 'bg-slate-50 text-slate-700 border-slate-200', event)" class="bank-opt p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-slate-50/60 transition text-xs font-bold text-slate-800 border-t border-rose-100/60 pt-2">
                      <span class="flex items-center gap-2"><span class="px-1.5 py-0.5 rounded-md text-[10px] bg-slate-200 text-slate-800 font-bold">OTHER</span><span>อื่นๆ</span></span>
                      <span class="check-b hidden text-slate-600">✓</span>
                    </div>
                  </div>
                </div>
              </div>



              <div>

                <label>เลขที่บัญชี / เบอร์พร้อมเพย์ <span class="text-rose-500">*</span></label>

                <input type="text" name="bankAccount" id="bankAccountInput" required placeholder="เฉพาะตัวเลข (8-15 หลัก)" maxlength="15" oninput="this.value = this.value.replace(/[^0-9]/g, '')">

                <span id="accWarn" class="text-[11px] text-rose-500 font-semibold mt-1 hidden block"></span>

              </div>



              <div>

                <label>ชื่อเจ้าของบัญชี <span class="text-rose-500">*</span></label>

                <input type="text" name="accountName" id="accountNameInput" required value="<?php echo htmlspecialchars($myLease['tenantFullName']); ?>" placeholder="ชื่อ-นามสกุล ตรงกับหน้าสมุดบัญชี">

              </div>



              <div class="sm:col-span-3 text-xs text-slate-500 bg-rose-50/30 p-3.5 rounded-xl border border-rose-100/80">

                💡 <b>หมายเหตุ:</b> นิติบุคคลจะใช้ข้อมูลบัญชีนี้ในการโอนเงินส่วนต่างค่าประกันคืนให้อัตโนมัติ ภายหลังจากการหักค่าน้ำ ค่าน้ำประปา ค่าทำความสะอาด และค่าเสียหาย (ถ้ามี)

              </div>

            </div>

        </div>







        <div class="pt-2">

          <button type="submit" name="submit_move_out" class="w-full py-3.5 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl shadow-lg shadow-rose-500/20 flex items-center justify-center gap-2 transition-all cursor-pointer">

            <i data-lucide="send" class="w-5 h-5"></i> ยืนยันการส่งคำขอแจ้งย้ายออก

          </button>

        </div>



      </form>

    <?php endif; ?>



  </main>





  <?php include_once 'includes/doc_viewer_modal.php'; ?>



  <script>

    lucide.createIcons();



    // ⚡ 1. การแจ้งเตือนขึ้นแปปเดียว (2.5 วินาทีแล้วจางหายอัตโนมัติ)

    document.addEventListener('DOMContentLoaded', function() {

      const alerts = document.querySelectorAll('#flashAlert, .alert-box');

      alerts.forEach(function(flash) {

        setTimeout(() => {

          flash.style.opacity = '0';

          flash.style.transform = 'translateY(-8px)';

          setTimeout(() => flash.remove(), 400);

        }, 2500);

      });

    });



    function applyReasonSelect(val) {

        const textEl = document.getElementById('reasonText');

        if (!textEl) return;

        if (val === 'custom') {

            textEl.value = '';

            textEl.focus();

        } else {

            textEl.value = val;

        }

    }



    function validateMoveOutForm(e) {

        if (e) e.preventDefault();



        const moveOutDate = document.getElementById('moveOutDateInput').value;

        const bankName = document.getElementById('bankNameInput').value;

        const bankAcc = document.getElementById('bankAccountInput').value;

        const accName = document.getElementById('accountNameInput').value;



        if (!moveOutDate) {

            Swal.fire({

                icon: 'warning',

                title: 'กรุณาระบุข้อมูล',

                text: 'กรุณาระบุวันที่ประสงค์จะย้ายออก',

                confirmButtonText: 'ตกลง',

                confirmButtonColor: '#3b82f6',

                customClass: { popup: 'rounded-3xl shadow-2xl font-sans border border-rose-100/60 p-6' }

            });

            return false;

        }



        if (!bankName) {

            Swal.fire({

                icon: 'warning',

                title: 'กรุณาระบุข้อมูล',

                text: 'กรุณาเลือกธนาคารสำหรับรับเงินคืน',

                confirmButtonText: 'ตกลง',

                confirmButtonColor: '#3b82f6',

                customClass: { popup: 'rounded-3xl shadow-2xl font-sans border border-rose-100/60 p-6' }

            });

            return false;

        }



        if (!bankAcc || bankAcc.length < 8) {

            Swal.fire({

                icon: 'warning',

                title: 'ข้อมูลไม่ถูกต้อง',

                text: 'กรุณากรอกเลขที่บัญชีธนาคารให้ถูกต้อง (ตัวเลข 8-15 หลัก)',

                confirmButtonText: 'ตกลง',

                confirmButtonColor: '#3b82f6',

                customClass: { popup: 'rounded-3xl shadow-2xl font-sans border border-rose-100/60 p-6' }

            });

            return false;

        }



        if (!accName) {

            Swal.fire({

                icon: 'warning',

                title: 'กรุณาระบุข้อมูล',

                text: 'กรุณากรอกชื่อเจ้าของบัญชี',

                confirmButtonText: 'ตกลง',

                confirmButtonColor: '#3b82f6',

                customClass: { popup: 'rounded-3xl shadow-2xl font-sans border border-rose-100/60 p-6' }

            });

            return false;

        }



        let formattedDate = moveOutDate;

        const dParts = moveOutDate.split('-');

        if (dParts.length === 3) {

            formattedDate = `${dParts[2]}/${dParts[1]}/${dParts[0]}`;

        }



        Swal.fire({

            title: '📦 ยืนยันการส่งคำขอแจ้งย้ายออก?',

            html: `คุณต้องการยื่นคำขอแจ้งย้ายออกในวันที่ <b class="text-rose-600 font-mono text-base">${formattedDate}</b> ใช่หรือไม่?<br><span class="text-xs text-slate-500 mt-2 block">ทางนิติบุคคลจะได้รับเรื่องและติดต่อประสานงานตรวจเช็คห้องพักตามนัดหมาย</span>`,

            icon: 'question',

            showCancelButton: true,

            confirmButtonColor: '#e11d48',

            cancelButtonColor: '#64748b',

            confirmButtonText: 'ใช่, ยืนยันส่งคำขอ',

            cancelButtonText: 'ยกเลิก',

            reverseButtons: true,

            customClass: {

                popup: 'rounded-3xl shadow-2xl font-sans border border-rose-100/60 p-6',

                confirmButton: 'rounded-xl px-5 py-2.5 font-bold text-sm shadow-md',

                cancelButton: 'rounded-xl px-5 py-2.5 font-semibold text-sm'

            }

        }).then((result) => {

            if (result.isConfirmed) {

                const form = document.getElementById('moveOutForm');

                if (!form.querySelector('input[name="submit_move_out"]')) {

                    let hidden = document.createElement('input');

                    hidden.type = 'hidden';

                    hidden.name = 'submit_move_out';

                    hidden.value = '1';

                    form.appendChild(hidden);

                }

                form.setAttribute('data-swal-submitted', 'true');

                form.submit();

            }

        });



        return false;

    }

  
    function toggleCustomDropdown(menuId, chevronId, e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById(menuId);
        const chevron = document.getElementById(chevronId);
        // Close others
        ['reasonDropdownMenu', 'bankDropdownMenu'].forEach(id => {
            if (id !== menuId) {
                const el = document.getElementById(id);
                if (el) el.classList.add('hidden');
            }
        });
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

    function selectReasonOption(val, icon, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('reasonSelectInput');
        if (input) input.value = val;
        
        const triggerText = document.getElementById('reasonTriggerText');
        if (triggerText) triggerText.innerText = (val === 'custom') ? 'อื่นๆ (ระบุเอง)' : val;
        
        const triggerIcon = document.getElementById('reasonTriggerIcon');
        if (triggerIcon) triggerIcon.innerText = icon;

        const textEl = document.getElementById('reasonText');
        if (textEl) {
            if (val === 'custom') {
                textEl.value = '';
                textEl.focus();
            } else {
                textEl.value = val;
            }
        }

        const menu = document.getElementById('reasonDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('reasonChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    function selectBankOption(val, icon, badge, badgeClass, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('bankNameInput');
        if (input) input.value = val;

        const triggerText = document.getElementById('bankTriggerText');
        if (triggerText) {
            triggerText.innerText = val;
            triggerText.className = 'text-slate-800 font-bold';
        }

        const triggerBadge = document.getElementById('bankTriggerBadge');
        if (triggerBadge) {
            triggerBadge.innerText = badge;
            triggerBadge.className = 'px-1.5 py-0.5 rounded-md text-[10px] font-bold ' + badgeClass;
        }

        const menu = document.getElementById('bankDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('bankChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#customReasonDropdown')) {
            const rMenu = document.getElementById('reasonDropdownMenu');
            if (rMenu) rMenu.classList.add('hidden');
            const rCh = document.getElementById('reasonChevron');
            if (rCh) rCh.classList.remove('rotate-180');
        }
        if (!e.target.closest('#customBankDropdown')) {
            const bMenu = document.getElementById('bankDropdownMenu');
            if (bMenu) bMenu.classList.add('hidden');
            const bCh = document.getElementById('bankChevron');
            if (bCh) bCh.classList.remove('rotate-180');
        }
    });

</script>



  <?php if (isset($_GET['cancelled']) && $_GET['cancelled'] === '1'): ?>

    <script>

      document.addEventListener('DOMContentLoaded', function() {

          if (typeof Swal !== 'undefined') {

              Swal.fire({

                  title: 'ยกเลิกคำขอเรียบร้อยแล้ว!',

                  html: 'คุณได้ทำการยกเลิกคำขอแจ้งย้ายออกแล้ว<br><span class="text-xs text-slate-500 mt-2 block">สถานะห้องพักกลับสู่สัญญาเช่าปกติ และข้อมูลการขอย้ายออกจะไม่แสดงให้เห็นทั้งฝั่งผู้เช่าและแอดมิน</span>',

                  icon: 'success',

                  confirmButtonColor: '#10b981',

                  confirmButtonText: 'ตกลง',

                  customClass: {

                      popup: 'rounded-3xl shadow-2xl font-sans border border-rose-100/60 p-6',

                      confirmButton: 'rounded-xl px-6 py-2.5 font-bold text-sm shadow-md'

                  }

              });

          }

      });

    
    function toggleCustomDropdown(menuId, chevronId, e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById(menuId);
        const chevron = document.getElementById(chevronId);
        // Close others
        ['reasonDropdownMenu', 'bankDropdownMenu'].forEach(id => {
            if (id !== menuId) {
                const el = document.getElementById(id);
                if (el) el.classList.add('hidden');
            }
        });
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

    function selectReasonOption(val, icon, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('reasonSelectInput');
        if (input) input.value = val;
        
        const triggerText = document.getElementById('reasonTriggerText');
        if (triggerText) triggerText.innerText = (val === 'custom') ? 'อื่นๆ (ระบุเอง)' : val;
        
        const triggerIcon = document.getElementById('reasonTriggerIcon');
        if (triggerIcon) triggerIcon.innerText = icon;

        const textEl = document.getElementById('reasonText');
        if (textEl) {
            if (val === 'custom') {
                textEl.value = '';
                textEl.focus();
            } else {
                textEl.value = val;
            }
        }

        const menu = document.getElementById('reasonDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('reasonChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    function selectBankOption(val, icon, badge, badgeClass, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('bankNameInput');
        if (input) input.value = val;

        const triggerText = document.getElementById('bankTriggerText');
        if (triggerText) {
            triggerText.innerText = val;
            triggerText.className = 'text-slate-800 font-bold';
        }

        const triggerBadge = document.getElementById('bankTriggerBadge');
        if (triggerBadge) {
            triggerBadge.innerText = badge;
            triggerBadge.className = 'px-1.5 py-0.5 rounded-md text-[10px] font-bold ' + badgeClass;
        }

        const menu = document.getElementById('bankDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('bankChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#customReasonDropdown')) {
            const rMenu = document.getElementById('reasonDropdownMenu');
            if (rMenu) rMenu.classList.add('hidden');
            const rCh = document.getElementById('reasonChevron');
            if (rCh) rCh.classList.remove('rotate-180');
        }
        if (!e.target.closest('#customBankDropdown')) {
            const bMenu = document.getElementById('bankDropdownMenu');
            if (bMenu) bMenu.classList.add('hidden');
            const bCh = document.getElementById('bankChevron');
            if (bCh) bCh.classList.remove('rotate-180');
        }
    });

</script>

  <?php endif; ?>

</body>

</html>

