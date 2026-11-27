<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/mailer.php';

// ตรวจสอบสิทธิ์ ต้องเป็น Admin เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$adminName = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
$initials = strtoupper(mb_substr($adminName, 0, 2, 'UTF-8'));
$message = "";

try {
    $conn->exec("ALTER TABLE Invoices ADD COLUMN slipRejectReason TEXT NULL");
} catch(Throwable $e) {}
$step = 1; // ค่าเริ่มต้นคือขั้นตอนกรอกข้อมูล
$previewData = [];

// กำหนดแท็บเริ่มต้น (create หรือ list)
$activeTab = $_GET['tab'] ?? 'list';

// ขั้นตอนที่ 1: เมื่อแอดมินกดคำนวณเพื่อพรีวิวดูความถูกต้องก่อน
if (isset($_POST['preview_invoice'])) {
    $activeTab = 'create';
    $roomId = $_POST['roomId'];
    
    // แปลงวันที่จาก Calendar (YYYY-MM) เป็นภาษาไทย
    $rawMonth = trim($_POST['billingMonth']);
    $maxAllowedMonth = date('Y-m');

    // ตรวจสอบล็อกไม่ให้กรอกหรือเลือกรอบบิลล่วงหน้า (เลือกได้เฉพาะรอบเดือนปัจจุบันหรือย้อนหลัง) ยกเว้นมี presetReqId
    if ($rawMonth > $maxAllowedMonth && empty($_POST['presetReqId'])) {
        $message = "<div class='alert-box p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'>❌ ไม่สามารถออกบิลล่วงหน้าได้ (เลือกได้สูงสุดไม่เกินรอบเดือนปัจจุบัน {$maxAllowedMonth} หรือเลือกรอบย้อนหลัง)</div>";
        $step = 1;
    } else {
        if (preg_match('/^(\d{4})-(\d{2})$/', $rawMonth, $matches)) {
            $thaiMonths = [
                '01' => 'มกราคม', '02' => 'กุมภาพันธ์', '03' => 'มีนาคม', '04' => 'เมษายน',
                '05' => 'พฤษภาคม', '06' => 'มิถุนายน', '07' => 'กรกฎาคม', '08' => 'สิงหาคม',
                '09' => 'กันยายน', '10' => 'ตุลาคม', '11' => 'พฤศจิกายน', '12' => 'ธันวาคม'
            ];
            $billingMonth = $thaiMonths[$matches[2]] . ' ' . $matches[1];
        } else {
            $billingMonth = $rawMonth; // กรณีพิมพ์เอง
        }

        $waterPrev = min(9999, max(0, intval($_POST['waterPrev'])));
        $waterCurr = min(9999, max(0, intval($_POST['waterCurr'])));
        $waterRate = 18.00; // ล็อกไว้ 18.00 บาท/หน่วย ห้ามเปลี่ยนแปลงตามคำสั่ง
        $electricPrev = min(9999, max(0, intval($_POST['electricPrev'])));
        $electricCurr = min(9999, max(0, intval($_POST['electricCurr'])));
        $electricRate = 8.00; // ล็อกไว้ 8.00 บาท/หน่วย ห้ามเปลี่ยนแปลงตามคำสั่ง
        $otherFee = floatval($_POST['otherFee']);

        // ดึงข้อมูลห้องและผู้เช่า
        $stmtLease = $conn->prepare("
            SELECT l.tenantId, r.roomNumber, r.rentPrice, u.fullName 
            FROM Lease l 
            JOIN Room r ON l.roomId = r.roomId 
            JOIN User u ON l.tenantId = u.userId
            WHERE l.roomId = ? AND l.status = 'Active' 
            LIMIT 1
        ");
        $stmtLease->execute([$roomId]);
        $lease = $stmtLease->fetch(PDO::FETCH_ASSOC);

        if ($lease) {
            $waterUnits = max(0, $waterCurr - $waterPrev);
            $waterTotal = $waterUnits * $waterRate;

            $electricUnits = max(0, $electricCurr - $electricPrev);
            $electricTotal = $electricUnits * $electricRate;

            $totalAmount = $lease['rentPrice'] + $waterTotal + $electricTotal + $otherFee;

            // เก็บข้อมูลไว้แสดงในหน้าพรีวิว
            $previewData = [
                'roomId' => $roomId,
                'roomNumber' => $lease['roomNumber'],
                'tenantId' => $lease['tenantId'],
                'tenantName' => $lease['fullName'],
                'billingMonth' => $billingMonth,
                'rawMonth' => $rawMonth,
                'presetReqId' => trim($_POST['presetReqId'] ?? ''),
                'rentPrice' => $lease['rentPrice'],
                'waterPrev' => $waterPrev,
                'waterCurr' => $waterCurr,
                'waterUnits' => $waterUnits,
                'waterRate' => $waterRate,
                'waterTotal' => $waterTotal,
                'electricPrev' => $electricPrev,
                'electricCurr' => $electricCurr,
                'electricUnits' => $electricUnits,
                'electricRate' => $electricRate,
                'electricTotal' => $electricTotal,
                'otherFee' => $otherFee,
                'otherFeeNote' => trim($_POST['otherFeeNote'] ?? ''),
                'totalAmount' => $totalAmount
            ];
            $step = 2; // สลับไปหน้าพรีวิว
        } else {
            $message = "<div class='alert-box p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'>❌ ห้องนี้ยังไม่มีสัญญาเช่าที่เปิดใช้งานอยู่ ไม่สามารถออกบิลได้</div>";
        }
    }
}

// ขั้นตอนที่ 2: เมื่อแอดมินตรวจสอบเสร็จและกด "ยืนยันการออกบิล" จริง
if (isset($_POST['confirm_invoice'])) {
    $activeTab = 'list';
    $roomId = $_POST['roomId'];
    $tenantId = $_POST['tenantId'];
    $billingMonth = $_POST['billingMonth'];
    $rawMonth = trim($_POST['rawMonth'] ?? '');
    $presetReqId = intval($_POST['presetReqId'] ?? 0);
    $maxAllowedYear = (int)date('Y');
    $maxAllowedM = (int)date('n') + 1;
    if ($maxAllowedM > 12) {
        $maxAllowedM = 1;
        $maxAllowedYear++;
    }
    $maxAllowedMonth = sprintf('%04d-%02d', $maxAllowedYear, $maxAllowedM);

    if (!empty($rawMonth) && $rawMonth > $maxAllowedMonth) {
        $message = "<div class='alert-box p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'>❌ สามารถเลือกรอบบิลได้สูงสุดไม่เกินเดือนถัดไป 1 เดือน ({$maxAllowedMonth}) เท่านั้น</div>";
    } else {
        $rentPrice = floatval($_POST['rentPrice']);
        $waterPrev = min(9999, max(0, intval($_POST['waterPrev'])));
        $waterCurr = min(9999, max(0, intval($_POST['waterCurr'])));
        $waterRate = 18.00;
        $electricPrev = min(9999, max(0, intval($_POST['electricPrev'])));
        $electricCurr = min(9999, max(0, intval($_POST['electricCurr'])));
        $electricRate = 8.00;
        $otherFee = floatval($_POST['otherFee']);
        $otherFeeNote = trim($_POST['otherFeeNote'] ?? '');
        $totalAmount = floatval($_POST['totalAmount']);

        try {
            // ตรวจสอบว่าเคยออกบิลรอบเดือนนี้สำหรับห้องนี้แล้วหรือไม่
            $stmtCheck = $conn->prepare("SELECT invoiceId, status FROM Invoices WHERE roomId = ? AND billingMonth = ? ORDER BY invoiceId DESC LIMIT 1");
            $stmtCheck->execute([$roomId, $billingMonth]);
            $existingInvoice = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            // หากมีบิลอยู่แล้วและยังไม่ได้ชำระเงิน (Unpaid หรือ Pending Approval) ให้อัปเดตข้อมูลบิลนั้นได้
            // แต่ถ้าบิลเดิมมีสถานะ 'Paid' (ชำระแล้ว) แล้ว จะต้องไม่เขียนทับสถานะชำระเงินเด็ดขาด! ให้สร้างบิลใหม่แทน เพื่อไม่ให้ตัวเลขบิลที่ชำระแล้วลดลง
            if ($existingInvoice && $existingInvoice['status'] !== 'Paid') {
                $stmt = $conn->prepare("UPDATE Invoices SET tenantId = ?, rentPrice = ?, waterPrev = ?, waterCurr = ?, waterUnitRate = ?, electricPrev = ?, electricCurr = ?, electricUnitRate = ?, otherFee = ?, otherFeeNote = ?, totalAmount = ?, status = 'Unpaid', slipImage = NULL WHERE invoiceId = ?");
                $stmt->execute([$tenantId, $rentPrice, $waterPrev, $waterCurr, $waterRate, $electricPrev, $electricCurr, $electricRate, $otherFee, $otherFeeNote, $totalAmount, $existingInvoice['invoiceId']]);
                $message = "<div class='alert-box p-4 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> อัปเดตข้อมูลใบแจ้งหนี้ประจำเดือน {$billingMonth} เรียบร้อยแล้ว!</div>";
            } else {
                $stmt = $conn->prepare("INSERT INTO Invoices (roomId, tenantId, billingMonth, rentPrice, waterPrev, waterCurr, waterUnitRate, electricPrev, electricCurr, electricUnitRate, otherFee, otherFeeNote, totalAmount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Unpaid')");
                $stmt->execute([$roomId, $tenantId, $billingMonth, $rentPrice, $waterPrev, $waterCurr, $waterRate, $electricPrev, $electricCurr, $electricRate, $otherFee, $otherFeeNote, $totalAmount]);
                $message = "<div class='alert-box p-4 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> ออกใบแจ้งหนี้ประจำเดือน {$billingMonth} ส่งให้ผู้เช่าสำเร็จเรียบร้อยแล้ว!</div>";
            }
            $step = 1; // กลับสู่หน้าปกติ

            // หากมาจากการออกบิลตามคำขอย้อนหลัง ให้อัปเดตสถานะคำขอเป็น Approved/เรียบร้อย
            $finalInvoiceId = $existingInvoice ? (int)$existingInvoice['invoiceId'] : (int)$conn->lastInsertId();
            if ($presetReqId > 0) {
                try {
                    $conn->prepare("UPDATE RetroactiveInvoiceRequests SET status = 'Approved', invoiceId = ?, adminRemark = 'ออกบิลเรียบร้อยแล้ว', updatedAt = NOW() WHERE requestId = ?")->execute([$finalInvoiceId, $presetReqId]);
                } catch(PDOException $e) {}
            }
            // อัปเดตคำขอที่รออนุมัติสำหรับห้องและเดือนนี้ด้วยหากมี
            try {
                if (!empty($rawMonth)) {
                    $conn->prepare("UPDATE RetroactiveInvoiceRequests SET status = 'Approved', invoiceId = ?, adminRemark = 'ออกบิลเรียบร้อยแล้ว', updatedAt = NOW() WHERE roomId = ? AND (requestedMonth = ? OR requestedMonth LIKE ?) AND status = 'Pending'")->execute([$finalInvoiceId, $roomId, $rawMonth, '%' . $rawMonth . '%']);
                }
            } catch(PDOException $e) {}

        // แจ้งลูกบ้านว่ามีบิลใหม่
        $stmtTenant = $conn->prepare("
            SELECT u.fullName, u.email, r.roomNumber
            FROM User u
            LEFT JOIN Room r ON r.roomId = ?
            WHERE u.userId = ?
            LIMIT 1
        ");
        $stmtTenant->execute([$roomId, $tenantId]);
        $tenantInfo = $stmtTenant->fetch(PDO::FETCH_ASSOC);
        if ($tenantInfo && !empty($tenantInfo['email'])) {
            $safeName = htmlspecialchars($tenantInfo['fullName'] ?? '', ENT_QUOTES, 'UTF-8');
            $safeMonth = htmlspecialchars($billingMonth, ENT_QUOTES, 'UTF-8');
            $safeRoom = htmlspecialchars($tenantInfo['roomNumber'] ?? '', ENT_QUOTES, 'UTF-8');
            $safeTotal = number_format($totalAmount, 2);
            sendMail(
                $tenantInfo['email'],
                "[หอพักจัสมิน] ใบแจ้งหนี้ประจำเดือน {$billingMonth} ห้อง {$tenantInfo['roomNumber']}",
                "<h2>สวัสดีคุณ {$safeName}</h2>
                <p>มีใบแจ้งหนี้ค่าเช่า/สาธารณูปโภคใหม่</p>
                <p><b>ห้อง:</b> {$safeRoom}<br>
                <b>งวด:</b> {$safeMonth}<br>
                <b>ยอดรวม:</b> {$safeTotal} บาท</p>
                <p>กรุณาเข้าสู่ระบบเพื่อดูรายละเอียดและอัปโหลดสลิปชำระเงิน</p>"
            );
        }
    } catch(PDOException $e) {
        $message = "<div class='alert-box p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
    }
    }
}

// 3. เมื่อแอดมินกดอนุมัติหรือเปลี่ยนสถานะสลิปชำระเงิน
if (isset($_POST['update_payment_status'])) {
    $activeTab = 'list';
    $invoiceId = $_POST['invoiceId'];
    $newStatus = $_POST['update_payment_status']; 

    $stmtStatus = $conn->prepare("UPDATE Invoices SET status = ? WHERE invoiceId = ?");
    $stmtStatus->execute([$newStatus, $invoiceId]);
    
    $statusText = ($newStatus === 'Paid') ? 'อนุมัติการชำระเงินเรียบร้อยแล้ว' : 'เปลี่ยนสถานะกลับเป็นรอชำระเงินเรียบร้อยแล้ว';
    $message = "<div class='alert-box p-4 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> {$statusText}</div>";

    // แจ้งผลอนุมัติ / ปฏิเสธสลิปผ่านทางอีเมล
    if (in_array($newStatus, ['Paid', 'Unpaid'], true)) {
        $stmtInv = $conn->prepare("
            SELECT i.billingMonth, i.totalAmount, u.fullName, u.email, r.roomNumber
            FROM Invoices i
            JOIN User u ON i.tenantId = u.userId
            LEFT JOIN Room r ON i.roomId = r.roomId
            WHERE i.invoiceId = ?
            LIMIT 1
        ");
        $stmtInv->execute([$invoiceId]);
        $invInfo = $stmtInv->fetch(PDO::FETCH_ASSOC);
        if ($invInfo && !empty($invInfo['email'])) {
            $safeName = htmlspecialchars($invInfo['fullName'] ?? '', ENT_QUOTES, 'UTF-8');
            $safeMonth = htmlspecialchars($invInfo['billingMonth'] ?? '', ENT_QUOTES, 'UTF-8');
            $safeRoom = htmlspecialchars($invInfo['roomNumber'] ?? '', ENT_QUOTES, 'UTF-8');
            $safeTotal = number_format((float)($invInfo['totalAmount'] ?? 0), 2);
            if ($newStatus === 'Paid') {
                sendMail(
                    $invInfo['email'],
                    "[หอพักจัสมิน] ยืนยันการชำระเงินบิล {$invInfo['billingMonth']} เรียบร้อยแล้ว",
                    "<h2>สวัสดีคุณ {$safeName}</h2>
                    <p>นิติบุคคลได้อนุมัติสลิปชำระเงินของท่านแล้ว</p>
                    <p><b>ห้อง:</b> {$safeRoom}<br>
                    <b>งวด:</b> {$safeMonth}<br>
                    <b>ยอด:</b> {$safeTotal} บาท<br>
                    <b>สถานะ:</b> ชำระแล้ว</p>"
                );
            } else {
                sendMail(
                    $invInfo['email'],
                    "[หอพักจัสมิน] สลิปชำระเงินบิล {$invInfo['billingMonth']} ยังไม่ผ่านการอนุมัติ",
                    "<h2>สวัสดีคุณ {$safeName}</h2>
                    <p>สลิปชำระเงินของท่านยังไม่ผ่านการอนุมัติ หรือสถานะถูกตั้งกลับเป็นยังไม่ชำระ</p>
                    <p><b>ห้อง:</b> {$safeRoom}<br>
                    <b>งวด:</b> {$safeMonth}<br>
                    <b>ยอด:</b> {$safeTotal} บาท</p>
                    <p>กรุณาเข้าสู่ระบบเพื่อตรวจสอบและอัปโหลดสลิปใหม่หากจำเป็น</p>"
                );
            }
        }
    }
}

// 4. เมื่อแอดมินกดยกเลิก/ปฏิเสธสลิป เพื่อส่งกลับให้ลูกบ้านแนบใหม่
if (isset($_POST['reject_slip'])) {
    $activeTab = 'list';
    $invoiceId = intval($_POST['invoiceId'] ?? 0);
    $rejectReason = trim($_POST['rejectReason'] ?? '');
    if (empty($rejectReason)) {
        $rejectReason = 'ข้อมูลสลิปไม่ถูกต้องหรือไม่ชัดเจน กรุณาตรวจสอบและแนบสลิปใหม่อีกครั้ง';
    }

    $stmtChk = $conn->prepare("
        SELECT i.*, u.fullName, u.email, r.roomNumber 
        FROM Invoices i
        JOIN User u ON i.tenantId = u.userId
        LEFT JOIN Room r ON i.roomId = r.roomId
        WHERE i.invoiceId = ?
        LIMIT 1
    ");
    $stmtChk->execute([$invoiceId]);
    $invInfo = $stmtChk->fetch(PDO::FETCH_ASSOC);

    if ($invInfo) {
        $currSlip = $invInfo['slipImage'] ?? null;
        $slipToSave = !empty($currSlip) ? $currSlip : ($invInfo['oldSlipImage'] ?? null);
        $stmtRej = $conn->prepare("
            UPDATE Invoices 
            SET status = 'Unpaid',
                slipStatus = 'Rejected',
                slipRejectReason = ?,
                slipRejectedAt = NOW(),
                oldSlipImage = ?,
                slipImage = NULL
            WHERE invoiceId = ?
        ");
        $stmtRej->execute([$rejectReason, $slipToSave, $invoiceId]);

        // ส่งอีเมลแจ้งเตือนผู้เช่า
        if (!empty($invInfo['email'])) {
            $safeName = htmlspecialchars($invInfo['fullName'] ?? '', ENT_QUOTES, 'UTF-8');
            $safeMonth = htmlspecialchars($invInfo['billingMonth'] ?? '', ENT_QUOTES, 'UTF-8');
            $safeRoom = htmlspecialchars($invInfo['roomNumber'] ?? '', ENT_QUOTES, 'UTF-8');
            $safeTotal = number_format((float)($invInfo['totalAmount'] ?? 0), 2);
            $safeReason = htmlspecialchars($rejectReason, ENT_QUOTES, 'UTF-8');
            try {
                sendMail(
                    $invInfo['email'],
                    "[หอพักจัสมิน] แจ้งเตือน: สลิปโอนเงินรอบเดือน {$safeMonth} ไม่ผ่านการอนุมัติ (กรุณาแนบใหม่)",
                    "<h2>สวัสดีคุณ {$safeName}</h2>
                    <p>นิติบุคคลได้ตรวจสอบสลิปชำระเงินของท่านสำหรับห้อง <b>{$safeRoom}</b> รอบเดือน <b>{$safeMonth}</b> (ยอด {$safeTotal} บาท) แล้วพบว่า <b>ไม่ผ่านการอนุมัติ</b></p>
                    <div style='background: #fff1f2; border-left: 4px solid #f43f5e; padding: 12px; margin: 15px 0; border-radius: 6px;'>
                        <b style='color: #be123c;'>เหตุผลจากเจ้าหน้าที่:</b><br>{$safeReason}
                    </div>
                    <p>กรุณาเข้าสู่ระบบหอพักจัสมินเพื่อแนบสลิปการโอนเงินที่ถูกต้องใหม่อีกครั้ง</p>"
                );
            } catch (Exception $e) {
                // Ignore email error to not block system
            }
        }

        $message = "<div class='alert-box p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-800 rounded-2xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'><i data-lucide='alert-circle' class='w-5 h-5 text-rose-600'></i> ปฏิเสธสลิปบิล #INV-{$invoiceId} เรียบร้อยแล้ว (สถานะถูกส่งกลับให้ผู้เช่าแนบสลิปใหม่)</div>";
    }
}

// 5. เมื่อแอดมินกดอนุมัติคำขอออกบิลย้อนหลัง
if (isset($_POST['approve_retro_request'])) {
    $activeTab = 'retro';
    $requestId = intval($_POST['requestId'] ?? 0);
    $customRemark = trim($_POST['adminRemark'] ?? '');
    if ($requestId > 0) {
        try {
            $reqQ = $conn->prepare("SELECT * FROM RetroactiveInvoiceRequests WHERE requestId = ?");
            $reqQ->execute([$requestId]);
            $reqRow = $reqQ->fetch(PDO::FETCH_ASSOC);
            $invIdToLink = $reqRow['invoiceId'] ?? null;
            if (!$invIdToLink && $reqRow) {
                $thM = toThaiBillingMonth($reqRow['requestedMonth']);
                $chkInv = $conn->prepare("SELECT invoiceId FROM Invoices WHERE roomId = ? AND (billingMonth = ? OR billingMonth = ? OR billingMonth LIKE ?) ORDER BY invoiceId DESC LIMIT 1");
                $chkInv->execute([$reqRow['roomId'], $thM, $reqRow['requestedMonth'], '%' . $reqRow['requestedMonth'] . '%']);
                $invIdToLink = $chkInv->fetchColumn() ?: null;
            }

            $remark = !empty($customRemark) ? $customRemark : 'อนุมัติคำขอเรียบร้อยแล้ว ตรวจสอบประวัติบิลในระบบได้ทันที';
            $stmtApprove = $conn->prepare("UPDATE RetroactiveInvoiceRequests SET status = 'Approved', invoiceId = COALESCE(?, invoiceId), adminRemark = ?, updatedAt = NOW() WHERE requestId = ?");
            $stmtApprove->execute([$invIdToLink, $remark, $requestId]);
            $message = "<div class='alert-box p-4 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> อนุมัติคำขอบิลย้อนหลังเรียบร้อยแล้ว</div>";
        } catch(PDOException $e) {
            $message = "<div class='alert-box p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
        }
    }
}

// 6. เมื่อแอดมินกดยกเลิกคำขอออกบิลย้อนหลัง
if (isset($_POST['reject_retro_request'])) {
    $activeTab = 'retro';
    $requestId = intval($_POST['requestId'] ?? 0);
    $adminRemark = trim($_POST['adminRemark'] ?? '');
    if (empty($adminRemark)) {
        $adminRemark = 'ผู้ดูแลระบบยกเลิกคำขอ';
    }
    if ($requestId > 0) {
        try {
            $stmtReject = $conn->prepare("UPDATE RetroactiveInvoiceRequests SET status = 'Rejected', adminRemark = ?, updatedAt = NOW() WHERE requestId = ?");
            $stmtReject->execute([$adminRemark, $requestId]);
            $message = "<div class='alert-box p-4 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'><i data-lucide='x-circle' class='w-5 h-5 text-amber-600'></i> ยกเลิกคำขอออกบิลย้อนหลังเรียบร้อยแล้ว</div>";
        } catch(PDOException $e) {
            $message = "<div class='alert-box p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-semibold flex items-center gap-2 mb-6'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
        }
    }
}

// รับค่าจากปุ่ม 'ออกบิลตามคำขอ' (Preset Parameters)
$presetRoomId = isset($_GET['roomId']) ? intval($_GET['roomId']) : null;
$presetMonth = isset($_GET['presetMonth']) ? trim($_GET['presetMonth']) : null;
$presetReqId = isset($_GET['reqId']) ? intval($_GET['reqId']) : null;
if ($presetRoomId && $presetMonth) {
    $activeTab = 'create';
}

// ดึงคำขอรอบบิลย้อนหลังทั้งหมด
$retroRequests = [];
try {
    // Auto-link any requests with null invoiceId if matching invoice exists in Invoices
    try {
        $unlinked = $conn->query("SELECT requestId, roomId, requestedMonth FROM RetroactiveInvoiceRequests WHERE invoiceId IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($unlinked as $un) {
            $thM = toThaiBillingMonth($un['requestedMonth']);
            $chk = $conn->prepare("SELECT invoiceId FROM Invoices WHERE roomId = ? AND (billingMonth = ? OR billingMonth = ? OR billingMonth LIKE ?) ORDER BY invoiceId DESC LIMIT 1");
            $chk->execute([$un['roomId'], $thM, $un['requestedMonth'], '%' . $un['requestedMonth'] . '%']);
            $fId = $chk->fetchColumn();
            if ($fId) {
                $conn->prepare("UPDATE RetroactiveInvoiceRequests SET invoiceId = ? WHERE requestId = ?")->execute([$fId, $un['requestId']]);
            }
        }
    } catch(Throwable $e) {}

    $stmtRetro = $conn->query("
        SELECT r.*, rm.roomNumber, u.fullName AS tenantName, u.phone AS tenantPhone,
               COALESCE(r.invoiceId, inv_direct.invoiceId, inv.invoiceId) AS matchedInvoiceId,
               COALESCE(inv_direct.status, inv.status) AS matchedInvoiceStatus, 
               COALESCE(inv_direct.totalAmount, inv.totalAmount) AS matchedTotalAmount,
               COALESCE(inv_direct.billingMonth, inv.billingMonth) AS matchedBillingMonth
        FROM RetroactiveInvoiceRequests r
        JOIN Room rm ON r.roomId = rm.roomId
        JOIN User u ON r.tenantId = u.userId
        LEFT JOIN Invoices inv_direct ON inv_direct.invoiceId = r.invoiceId
        LEFT JOIN Invoices inv ON (inv.roomId = r.roomId AND (
            inv.billingMonth = r.requestedMonth COLLATE utf8mb4_general_ci 
            OR inv.billingMonth LIKE CONCAT('%', r.requestedMonth COLLATE utf8mb4_general_ci, '%')
        ))
        ORDER BY CASE WHEN r.status = 'Pending' THEN 1 WHEN r.status = 'Approved' THEN 2 ELSE 3 END, r.requestId DESC
    ");
    if ($stmtRetro) {
        $retroRequests = $stmtRetro->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(Throwable $e) {}
$pendingRetroCount = count(array_filter($retroRequests, function($req) {
    return ($req['status'] ?? '') === 'Pending';
}));
// ดึงรายการห้องที่มีผู้เช่าอยู่จริง พร้อมเลขมิเตอร์น้ำ-ไฟล่าสุดของแต่ละห้อง และข้อมูลสัญญาค่าบริการ (ที่จอดรถ, เน็ต)
$rooms = $conn->query("
    SELECT r.roomId, r.roomNumber, r.rentPrice, u.fullName, l.tenantId,
           l.initialWaterMeter, l.initialElectricMeter, l.startDate AS leaseStartDate,
           l.parkingPlan, l.parkingFee, l.internetPlan, l.internetFee, l.vehicleType,
           pr.prVehicleType, pr.prPlate, pr.prStatus,
           latestInv.waterCurr AS lastWater,
           latestInv.electricCurr AS lastElectric,
           latestInv.billingMonth AS lastBillingMonth,
           latestInv.invoiceId AS lastInvoiceId
    FROM Room r 
    JOIN Lease l ON r.roomId = l.roomId AND l.status = 'Active'
    JOIN User u ON l.tenantId = u.userId
    LEFT JOIN (
        SELECT pr1.tenantId, pr1.vehicleType AS prVehicleType, pr1.additionalPlate AS prPlate, 'Approved' AS prStatus
        FROM parking_requests pr1
        JOIN (
            SELECT tenantId, MAX(id) AS maxPrId
            FROM parking_requests
            WHERE status = 'Approved'
            GROUP BY tenantId
        ) pr2 ON pr1.id = pr2.maxPrId
    ) pr ON l.tenantId = pr.tenantId
    LEFT JOIN (
        SELECT i1.roomId, i1.waterCurr, i1.electricCurr, i1.billingMonth, i1.invoiceId
        FROM Invoices i1
        JOIN (
            SELECT roomId, MAX(invoiceId) AS maxId
            FROM Invoices
            GROUP BY roomId
        ) i2 ON i1.invoiceId = i2.maxId
    ) latestInv ON r.roomId = latestInv.roomId
    ORDER BY r.roomNumber ASC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rooms as &$r) {
    $r['parkingSource'] = 'lease';
    // หากสัญญาเช่าระบุ None หรือ 0 แต่ผู้เช่ามีรายการที่จอดรถที่ได้รับอนุมัติแล้วใน parking_requests
    if (($r['parkingPlan'] === 'None' || empty($r['parkingPlan']) || (float)$r['parkingFee'] <= 0) && !empty($r['prStatus'])) {
        $r['parkingPlan'] = 'Monthly';
        $r['parkingFee'] = 100.00;
        if (!empty($r['prVehicleType'])) {
            $r['vehicleType'] = $r['prVehicleType'];
        }
        $r['parkingSource'] = 'approved';
    }
}
unset($r);

// ดึงประวัติเลขมิเตอร์ตามห้องและรอบเดือน เพื่อให้ระบบเปลี่ยนเลขมิเตอร์เก่าตามรอบเดือนที่เลือกได้แบบไดนามิก
$roomMetersHistory = [];
try {
    $stmtHist = $conn->query("
        SELECT roomId, billingMonth, waterCurr, electricCurr, invoiceId
        FROM Invoices
        ORDER BY invoiceId DESC
    ");
    while($row = $stmtHist->fetch(PDO::FETCH_ASSOC)) {
        $rId = (int)$row['roomId'];
        if (!isset($roomMetersHistory[$rId])) {
            $roomMetersHistory[$rId] = [];
        }
        $roomMetersHistory[$rId][] = [
            'invoiceId' => (int)$row['invoiceId'],
            'billingMonth' => $row['billingMonth'],
            'waterCurr' => (float)$row['waterCurr'],
            'electricCurr' => (float)$row['electricCurr']
        ];
    }
} catch(PDOException $e) {}

// ดึงประวัติบิลทั้งหมด
$invoices = $conn->query("
    SELECT i.*, r.roomNumber, u.fullName, u.phone, u.email 
    FROM Invoices i 
    JOIN Room r ON i.roomId = r.roomId 
    JOIN User u ON i.tenantId = u.userId 
    ORDER BY i.invoiceId DESC
")->fetchAll(PDO::FETCH_ASSOC);

// สถิติและจำนวนบิล
$totalInvoicesCount = count($invoices);
$pendingApprovalCount = 0;
$unpaidCount = 0;
$unpaidSum = 0;
$paidCount = 0;
$paidSum = 0;

foreach ($invoices as $inv) {
    if ($inv['status'] === 'Pending Approval') {
        $pendingApprovalCount++;
    } elseif ($inv['status'] === 'Unpaid') {
        $unpaidCount++;
        $unpaidSum += (float)$inv['totalAmount'];
    } elseif ($inv['status'] === 'Paid') {
        $paidCount++;
        $paidSum += (float)$inv['totalAmount'];
    }
}

// ถ้าอยู่ในโหมดพรีวิว Step 2 ให้บังคับเปิดแท็บสร้างบิล
if ($step === 2) {
    $activeTab = 'create';
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
    body {
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
    @keyframes modalFadeIn {
      from { opacity: 0; transform: scale(0.96) translateY(6px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .metric-card {
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .metric-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
    }
  </style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white bg-[#FAF9F6] text-slate-800">

  <?php include_once 'includes/admin_sidebar.php'; ?>

  <!-- 🌟 Top Navigation Bar 🌟 -->
    <!-- 🌟 Top Navigation Bar - Warm & Cozy Boutique Header 🌟 -->
    <!-- Top Navigation (Admin) - Sleek Dark Glassmorphism Header -->
  <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-rose-100/80 shadow-[0_1px_4px_0_rgba(244,63,94,0.03)]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-3">
          <button type="button" onclick="toggleAdminSidebar()" class="p-2 -ml-1 rounded-2xl text-slate-600 hover:text-slate-900 hover:bg-rose-50/70 border border-stone-200/70 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-rose-600 via-rose-500 to-pink-600 flex items-center justify-center text-white shadow-sm ring-1 ring-rose-500/20 shrink-0">
            <i data-lucide="receipt" class="w-5 h-5 text-amber-300"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>
              <span class="text-[10px] bg-blue-500/20 text-blue-300 font-bold px-2 py-0.5 rounded-full border border-blue-500/30 uppercase tracking-wider">INVOICES</span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">ระบบจัดการใบแจ้งหนี้และตรวจสอบสลิปโอนเงิน</p>
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

  <!-- 🌟 Main Content 🌟 -->
  <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <?php if (!empty($message)): ?>
      <div id="flashAlert">
        <?php echo $message; ?>
      </div>
    <?php endif; ?>

    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">
      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
          <div class="flex items-center gap-2 flex-wrap mb-2">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
              <i data-lucide="receipt" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>ระบบการเงินและค่าเช่า</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="sparkles" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>Billing & Payments Hub</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            ระบบจัดการใบแจ้งหนี้
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            ออกบิลประจำเดือน ตรวจสอบสลิปโอนเงิน และบันทึกมิเตอร์น้ำ-ไฟแบบเรียลไทม์
          </p>
        </div>
      </div>
    </div>

    <!-- 🌟 KPI Metric Cards (แนวนอนเรียงกัน 3 ช่อง) 🌟 -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6 mb-6 sm:mb-8">

      <div class="bg-white p-4 sm:p-5 rounded-3xl border border-rose-200/80 shadow-xs flex items-center gap-3 hover:border-rose-300 transition">
        <div class="w-11 h-11 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0 border border-rose-200/60 shadow-2xs">
          <i data-lucide="hourglass" class="w-5 h-5"></i>
        </div>
        <div>
          <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">รอชำระเงิน</div>
          <div class="text-xl sm:text-2xl font-display font-bold text-rose-600 flex items-baseline gap-1.5">
            <?php echo number_format($unpaidCount); ?>
            <span class="text-xs font-medium text-slate-400">รายการ</span>
          </div>
          <div class="text-[10px] text-rose-600/80 mt-0.5">ค้างชำระ ฿<?php echo number_format($unpaidSum, 2); ?></div>
        </div>
      </div>

      <div class="bg-white p-4 sm:p-5 rounded-3xl border border-emerald-200/80 shadow-xs flex items-center gap-3 hover:border-emerald-300 transition">
        <div class="w-11 h-11 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 border border-emerald-200/60 shadow-2xs">
          <i data-lucide="check-circle-2" class="w-5 h-5"></i>
        </div>
        <div>
          <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">ชำระแล้ว (Paid)</div>
          <div class="text-xl sm:text-2xl font-display font-bold text-emerald-700 flex items-baseline gap-1.5">
            <?php echo number_format($paidCount); ?>
            <span class="text-xs font-medium text-slate-400">รายการ</span>
          </div>
          <div class="text-[10px] text-emerald-600/80 mt-0.5">รับเงินแล้ว ฿<?php echo number_format($paidSum, 2); ?></div>
        </div>
      </div>

      <div class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200 shadow-xs flex items-center gap-3 hover:border-slate-300 transition">
        <div class="w-11 h-11 rounded-2xl bg-slate-100 text-slate-700 flex items-center justify-center shrink-0 border border-slate-200 shadow-2xs">
          <i data-lucide="file-spreadsheet" class="w-5 h-5"></i>
        </div>
        <div>
          <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">รวมบิลทั้งหมด</div>
          <div class="text-xl sm:text-2xl font-display font-bold text-slate-900 flex items-baseline gap-1.5">
            <?php echo number_format($totalInvoicesCount); ?>
            <span class="text-xs font-medium text-slate-400">รายการ</span>
          </div>
          <div class="text-[10px] text-slate-500 mt-0.5">ทุกรอบเดือนในระบบ</div>
        </div>
      </div>
    </div>

    <!-- 🌟 TAB BAR (Boutique Segmented Control) 🌟 -->
    <div class="bg-white/80 backdrop-blur-md p-1.5 rounded-2xl border border-rose-200/60 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3">
      <div class="flex items-center gap-1.5 w-full sm:w-auto">
        <button type="button" id="btn-tab-list" onclick="switchTab('list')" class="flex-1 sm:flex-none px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer <?php echo ($activeTab === 'list') ? 'bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 text-white shadow-md shadow-rose-500/20' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/80'; ?>">
          <i data-lucide="clipboard-list" class="w-4 h-4"></i>
          <span>รายการใบแจ้งหนี้ & ตรวจสอบสลิป</span>
          <?php if($pendingApprovalCount > 0): ?>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold <?php echo ($activeTab === 'list') ? 'bg-white/20 text-white' : 'bg-amber-100 text-amber-800 border border-amber-200'; ?>">
              <?php echo $pendingApprovalCount; ?> รอตรวจ
            </span>
          <?php endif; ?>
        </button>

        <button type="button" id="btn-tab-create" onclick="switchTab('create')" class="flex-1 sm:flex-none px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer <?php echo ($activeTab === 'create') ? 'bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 text-white shadow-md shadow-rose-500/20' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/80'; ?>">
          <i data-lucide="file-plus-2" class="w-4 h-4"></i>
          <span>+ ออกใบแจ้งหนี้รอบเดือนใหม่</span>
          <?php if($step === 2): ?>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-100 text-rose-700">พรีวิว</span>
          <?php endif; ?>
        </button>

        <button type="button" id="btn-tab-retro" onclick="switchTab('retro')" class="flex-1 sm:flex-none px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer <?php echo ($activeTab === 'retro') ? 'bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 text-white shadow-md shadow-rose-500/20' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/80'; ?>">
          <i data-lucide="history" class="w-4 h-4"></i>
          <span>คำขอบิลย้อนหลัง</span>
          <?php if($pendingRetroCount > 0): ?>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold <?php echo ($activeTab === 'retro') ? 'bg-white/20 text-white' : 'bg-amber-500 text-white'; ?>">
              <?php echo $pendingRetroCount; ?> คำขอใหม่
            </span>
          <?php endif; ?>
        </button>
      </div>

      <div class="text-xs text-slate-400 font-medium px-3 hidden sm:block flex items-center gap-1.5">
        <i data-lucide="sparkles" class="w-3.5 h-3.5 text-rose-400"></i> กดสลับแท็บเพื่อทำงานแยกกันได้ทันที
      </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 🌟 TAB 1: รายการใบแจ้งหนี้และการตรวจสอบสลิป (แยกออกมาอย่างชัดเจน) 🌟 -->
    <!-- ========================================================================= -->
    <div id="tab-content-list" class="<?php echo ($activeTab === 'list') ? '' : 'hidden'; ?> space-y-4">
      
      <!-- Toolbar: Search & Status Filters -->
      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex flex-col md:flex-row items-center justify-between gap-3">
        <!-- Search -->
        <div class="relative w-full md:w-80">
          <i data-lucide="search" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
          <input type="text" id="invoiceSearch" onkeyup="filterInvoices()" placeholder="ค้นหาเลขห้อง, ชื่อผู้เช่า, เลขบิล..." class="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white transition">
        </div>

        <!-- Filter Pills -->
        <div class="flex items-center gap-1.5 overflow-x-auto w-full md:w-auto pb-1 md:pb-0">
          <button type="button" onclick="setFilterStatus('all')" class="filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-800 text-white transition cursor-pointer" data-filter="all">
            ทั้งหมด (<?php echo $totalInvoicesCount; ?>)
          </button>
          <button type="button" onclick="setFilterStatus('Pending Approval')" class="filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-amber-50 hover:text-amber-700 transition cursor-pointer flex items-center gap-1.5" data-filter="Pending Approval">
            <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
            รอตรวจสอบสลิป (<?php echo $pendingApprovalCount; ?>)
          </button>
          <button type="button" onclick="setFilterStatus('Unpaid')" class="filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-rose-50 hover:text-rose-700 transition cursor-pointer" data-filter="Unpaid">
            รอชำระเงิน (<?php echo $unpaidCount; ?>)
          </button>
          <button type="button" onclick="setFilterStatus('Paid')" class="filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 transition cursor-pointer" data-filter="Paid">
            ชำระแล้ว (<?php echo $paidCount; ?>)
          </button>
        </div>
      </div>

      <!-- Table Card -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center">
              <i data-lucide="table-2" class="w-4 h-4"></i>
            </div>
            <div>
              <h3 class="font-display font-bold text-sm text-slate-800">รายการใบแจ้งหนี้และการตรวจสอบสลิป</h3>
              <p class="text-[11px] text-slate-500">คลิกที่แถวหรือปุ่มดูรายละเอียด เพื่อตรวจสอบรายการค่าใช้จ่ายและสลิปโอนเงินแบบเต็มจอ</p>
            </div>
          </div>
          <span id="rowCountDisplay" class="text-xs text-slate-400 font-medium">แสดงทั้งหมด <?php echo count($invoices); ?> รายการ</span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse" id="invoicesTable">
            <thead>
              <tr class="bg-slate-50/75 border-b border-slate-200/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                <th class="py-3.5 px-4">ID บิล</th>
                <th class="py-3.5 px-4">รอบเดือน</th>
                <th class="py-3.5 px-4">ห้อง / ผู้เช่า</th>
                <th class="py-3.5 px-4 text-right">ยอดรวมทั้งสิ้น</th>
                <th class="py-3.5 px-4 text-center">สถานะ / สลิปโอนเงิน</th>
                <th class="py-3.5 px-4 text-center">จัดการ (ตรวจสอบบิล)</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-xs">
              <?php if(count($invoices) > 0): foreach($invoices as $inv): ?>
              <tr class="hover:bg-rose-50/40 transition-colors invoice-row cursor-pointer" 
                  data-invoice-id="<?php echo $inv['invoiceId']; ?>"
                  data-status="<?php echo htmlspecialchars($inv['status']); ?>"
                  data-search="<?php echo strtolower($inv['invoiceId'] . ' ' . $inv['roomNumber'] . ' ' . $inv['fullName'] . ' ' . $inv['billingMonth']); ?>">
                
                <!-- ID -->
                <td class="py-3.5 px-4 font-mono font-bold">
                  <button type="button" 
                          onclick="event.stopPropagation(); openInvoiceDetailModal(<?php echo $inv['invoiceId']; ?>)" 
                          class="inline-flex items-center gap-1.5 font-mono font-bold text-rose-600 hover:text-white bg-rose-50 hover:bg-rose-600 px-2.5 py-1 rounded-xl border border-rose-200/80 shadow-2xs hover:shadow-xs transition cursor-pointer group" 
                          title="คลิกเพื่อดูรายละเอียดบิลแบบเต็มจอ">
                    <span>#INV-<?php echo $inv['invoiceId']; ?></span>
                    <i data-lucide="external-link" class="w-3 h-3 text-rose-400 group-hover:text-white transition"></i>
                  </button>
                </td>

                <!-- รอบเดือน -->
                <td class="py-3.5 px-4">
                  <span class="inline-flex items-center gap-1.5 font-bold text-amber-700 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200/60">
                    <i data-lucide="calendar" class="w-3.5 h-3.5 text-amber-600"></i>
                    <?php echo htmlspecialchars($inv['billingMonth']); ?>
                  </span>
                </td>

                <!-- ห้อง / ผู้เช่า -->
                <td class="py-3.5 px-4">
                  <div class="font-bold text-slate-800 flex items-center gap-1.5">
                    <span class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded-md font-mono text-[11px] border border-slate-200">ห้อง <?php echo $inv['roomNumber']; ?></span>
                  </div>
                  <div class="text-[11px] text-slate-500 mt-0.5"><?php echo htmlspecialchars($inv['fullName']); ?></div>
                </td>

                <!-- ยอดรวมทั้งสิ้น -->
                <td class="py-3.5 px-4 text-right">
                  <span class="font-display font-bold text-sm text-rose-600">
                    <?php echo number_format($inv['totalAmount'], 2); ?> ฿
                  </span>
                </td>

                <!-- สถานะ / สลิปโอนเงิน -->
                <td class="py-3.5 px-4 text-center">
                  <div class="flex flex-col items-center justify-center gap-1.5">
                    <?php if($inv['status'] === 'Unpaid'): ?>
                      <?php if(!empty($inv['slipRejectReason'])): ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200" title="<?php echo htmlspecialchars($inv['slipRejectReason'], ENT_QUOTES); ?>">
                          <i data-lucide="alert-triangle" class="w-3 h-3 text-rose-600"></i> ขอสลิปใหม่
                        </span>
                      <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                          <i data-lucide="hourglass" class="w-3 h-3 text-amber-500"></i> รอชำระเงิน
                        </span>
                      <?php endif; ?>
                      <?php if(!empty($inv['slipImage'])): ?>
                        <div class="flex items-center gap-1.5 mt-0.5">
                          <img src="<?php echo htmlspecialchars($inv['slipImage']); ?>" 
                               onclick="event.stopPropagation(); openInvoiceDetailModal(<?php echo $inv['invoiceId']; ?>)" 
                               class="w-7 h-7 rounded-lg object-cover border border-amber-300 shadow-2xs cursor-pointer hover:scale-110 transition" title="คลิกดูสลิป">
                          <button type="button" 
                                  onclick="event.stopPropagation(); openInvoiceDetailModal(<?php echo $inv['invoiceId']; ?>)"
                                  class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-100 hover:bg-amber-200 text-amber-800 rounded-lg text-[10px] font-bold transition cursor-pointer">
                            <i data-lucide="image" class="w-3.5 h-3.5"></i> ตรวจสลิปที่แนบ
                          </button>
                        </div>
                      <?php elseif(!empty($inv['slipRejectReason'])): ?>
                        <div class="mt-0.5">
                          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-700 border border-rose-200" title="สลิปถูกส่งกลับ: <?php echo htmlspecialchars($inv['slipRejectReason']); ?>">
                            <i data-lucide="alert-triangle" class="w-3 h-3 text-rose-500"></i> สลิปถูกส่งกลับ
                          </span>
                        </div>
                      <?php endif; ?>
                    
                    <?php elseif($inv['status'] === 'Pending Approval'): ?>
                      <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200 shadow-2xs animate-pulse">
                        <i data-lucide="alert-circle" class="w-3 h-3 text-blue-600"></i> รอตรวจสอบสลิป
                      </span>
                      <?php if(!empty($inv['slipImage'])): ?>
                        <div class="flex items-center gap-1.5 mt-0.5">
                          <img src="<?php echo htmlspecialchars($inv['slipImage']); ?>" 
                               onclick="event.stopPropagation(); openInvoiceDetailModal(<?php echo $inv['invoiceId']; ?>)" 
                               class="w-8 h-8 rounded-lg object-cover border-2 border-blue-400 shadow-xs cursor-pointer hover:scale-110 transition" title="คลิกดูสลิปแบบขยาย">
                          <button type="button" 
                                  onclick="event.stopPropagation(); openInvoiceDetailModal(<?php echo $inv['invoiceId']; ?>)"
                                  class="inline-flex items-center gap-1 px-2.5 py-1 bg-rose-500 hover:bg-rose-600 text-white rounded-lg text-[11px] font-bold shadow-xs transition cursor-pointer">
                            <i data-lucide="image" class="w-3.5 h-3.5"></i> ตรวจสลิปโอนเงิน
                          </button>
                        </div>
                      <?php endif; ?>

                    <?php elseif($inv['status'] === 'Paid'): ?>
                      <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <i data-lucide="check-circle-2" class="w-3 h-3 text-emerald-600"></i> ชำระแล้ว (Paid)
                      </span>
                      <div class="flex items-center justify-center gap-1.5 flex-wrap mt-0.5">
                        <?php if(!empty($inv['slipImage'])): ?>
                          <img src="<?php echo htmlspecialchars($inv['slipImage']); ?>" 
                               onclick="event.stopPropagation(); openInvoiceDetailModal(<?php echo $inv['invoiceId']; ?>)" 
                               class="w-6 h-6 rounded-md object-cover border border-emerald-300 shadow-2xs cursor-pointer hover:scale-110 transition" title="คลิกดูสลิป">
                          <button type="button" 
                                  onclick="event.stopPropagation(); openInvoiceDetailModal(<?php echo $inv['invoiceId']; ?>)"
                                  class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-100 hover:bg-emerald-200 text-emerald-800 rounded-md text-[10px] font-bold transition cursor-pointer">
                            <i data-lucide="image" class="w-3 h-3"></i> ดูสลิป
                          </button>
                        <?php endif; ?>
                        <a href="print_receipt.php?id=<?php echo $inv['invoiceId']; ?>" target="_blank" onclick="event.stopPropagation()" class="inline-flex items-center gap-1 px-2 py-0.5 bg-sky-500 hover:bg-sky-600 text-white rounded-md text-[10px] font-bold transition">
                          <i data-lucide="printer" class="w-3 h-3"></i> พิมพ์ใบเสร็จ
                        </a>
                      </div>
                    <?php endif; ?>
                  </div>
                </td>

                <!-- จัดการ (ตรวจสอบบิล) -->
                <td class="py-3.5 px-4 text-center">
                  <div class="flex items-center justify-center gap-1.5 flex-wrap">
                    <?php if($inv['status'] === 'Paid'): ?>
                      <!-- ฟอร์มเปลี่ยนสถานะกลับเป็น Unpaid -->
                      <form action="manage_invoice.php?tab=list" method="POST" class="inline-flex items-center justify-center gap-1.5 m-0" onsubmit="return handleStatusSubmit(event, this)">
                        <input type="hidden" name="invoiceId" value="<?php echo $inv['invoiceId']; ?>">
                        <button type="submit" name="update_payment_status" value="Unpaid" onclick="event.stopPropagation()" class="inline-flex items-center gap-1 px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-xs font-bold transition cursor-pointer" title="เปลี่ยนสถานะกลับเป็นรอชำระ">
                          <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i> ยกเลิกสถานะ
                        </button>
                      </form>
                    <?php elseif(!empty($inv['slipImage'])): ?>
                      <!-- ลูกบ้านแนบรูปสลิปแล้ว: ขึ้นปุ่มให้อนุมัติ และปุ่มปฏิเสธสลิป -->
                      <form action="manage_invoice.php?tab=list" method="POST" class="inline-flex items-center justify-center gap-1.5 m-0" onsubmit="return handleStatusSubmit(event, this)">
                        <input type="hidden" name="invoiceId" value="<?php echo $inv['invoiceId']; ?>">
                        <button type="submit" name="update_payment_status" value="Paid" onclick="event.stopPropagation()" class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs transition cursor-pointer" title="อนุมัติการชำระเงิน">
                          <i data-lucide="check" class="w-3.5 h-3.5"></i> อนุมัติยอด
                        </button>
                      </form>
                      <button type="button" onclick="event.stopPropagation(); openRejectSlipModal(<?php echo $inv['invoiceId']; ?>)" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-xs font-bold transition cursor-pointer" title="ปฏิเสธสลิป / ส่งกลับให้แนบใหม่">
                        <i data-lucide="x" class="w-3.5 h-3.5 text-rose-600"></i> ปฏิเสธ
                      </button>
                    <?php elseif(!empty($inv['slipRejectReason'])): ?>
                      <!-- สลิปถูกส่งกลับไปแล้ว รอผู้เช่าแนบใหม่ -->
                      <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl text-[11px] font-bold" title="เหตุผล: <?php echo htmlspecialchars($inv['slipRejectReason']); ?>">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-rose-500"></i> รอแนบสลิปใหม่
                      </span>
                    <?php else: ?>
                      <!-- ยังไม่แนบสลิป: ไม่ขึ้นปุ่มให้อนุมัติ ต้องรอให้ลูกบ้านแนบรูปมาก่อน -->
                      <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-50 border border-slate-200 text-slate-400 rounded-xl text-[11px] font-medium" title="รอลูกบ้านแนบรูปสลิปก่อน ปุ่มอนุมัติจึงจะปรากฏ">
                        <i data-lucide="clock" class="w-3.5 h-3.5 text-slate-400"></i> รอลูกบ้านแนบสลิป
                      </span>
                    <?php endif; ?>
                  </div>
                </td>

              </tr>
              <?php endforeach; else: ?>
              <tr>
                <td colspan="6" class="text-center py-12 text-slate-400">
                  <div class="flex flex-col items-center justify-center">
                    <i data-lucide="inbox" class="w-10 h-10 stroke-1 text-slate-300 mb-2"></i>
                    <span class="font-medium text-xs">ยังไม่มีรายการใบแจ้งหนี้ในระบบ</span>
                  </div>
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <!-- ========================================================================= -->
    <!-- 🌟 TAB 2: ออกใบแจ้งหนี้รอบเดือนใหม่ (ฟอร์มและพรีวิวคำนวณ) 🌟 -->
    <!-- ========================================================================= -->
    <div id="tab-content-create" class="<?php echo ($activeTab === 'create') ? '' : 'hidden'; ?> space-y-6">

      <!-- ถ้าอยู่ในขั้นตอนที่ 2: แสดงหน้าพรีวิวตรวจสอบข้อมูลก่อนส่ง -->
      <?php if($step === 2 && !empty($previewData)): ?>
      <div class="bg-gradient-to-br from-blue-50 to-indigo-50/70 border-2 border-blue-400/80 rounded-3xl p-6 shadow-sm">
        <div class="flex items-center justify-between pb-4 border-b border-blue-200/80">
          <div class="flex items-center gap-2.5">
            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center shadow-sm">
              <i data-lucide="clipboard-check" class="w-5 h-5"></i>
            </div>
            <div>
              <h3 class="font-display font-bold text-base text-blue-900">ขั้นตอนที่ 2: ตรวจสอบความถูกต้องของใบแจ้งหนี้ก่อนส่ง</h3>
              <p class="text-xs text-blue-700">กรุณาตรวจสอบรายการคำนวณด้านล่างนี้ให้ถูกต้อง หากเรียบร้อยแล้วกด "ยืนยันและออกใบแจ้งหนี้"</p>
            </div>
          </div>
          <span class="px-3 py-1 bg-blue-600 text-white rounded-full text-xs font-bold">ขั้นตอนพรีวิว</span>
        </div>

        <!-- Room & Tenant summary pill -->
        <div class="bg-white p-4 rounded-2xl border border-blue-200 shadow-xs mt-4 flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-3">
            <span class="w-10 h-10 rounded-xl bg-rose-50 text-rose-700 flex items-center justify-center font-bold text-sm border border-rose-200">
              <?php echo $previewData['roomNumber']; ?>
            </span>
            <div>
              <div class="text-xs text-slate-500 font-medium">ห้องพักและผู้เช่า</div>
              <div class="text-sm font-bold text-slate-800">ห้อง <?php echo $previewData['roomNumber']; ?> - <?php echo $previewData['tenantName']; ?></div>
            </div>
          </div>

          <div>
            <div class="text-xs text-slate-500 font-medium">รอบเดือน (Billing Month)</div>
            <div class="text-sm font-bold text-amber-600 flex items-center gap-1">
              <i data-lucide="calendar" class="w-3.5 h-3.5"></i> <?php echo $previewData['billingMonth']; ?>
            </div>
          </div>
        </div>

        <!-- Breakdown table -->
        <div class="mt-4 bg-white rounded-2xl border border-blue-200 shadow-xs overflow-hidden">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase">
              <tr>
                <th class="p-3.5">รายการค่าใช้จ่าย</th>
                <th class="p-3.5">รายละเอียดการคำนวณ</th>
                <th class="p-3.5 text-right">จำนวนเงิน (บาท)</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr>
                <td class="p-3.5 font-bold text-slate-800">ค่าเช่าห้องพัก</td>
                <td class="p-3.5 text-slate-500">อัตราตามสัญญาเช่า</td>
                <td class="p-3.5 text-right font-bold text-slate-900"><?php echo number_format($previewData['rentPrice'], 2); ?> ฿</td>
              </tr>
              <tr>
                <td class="p-3.5 font-bold text-sky-800">ค่าน้ำประปา</td>
                <td class="p-3.5 text-slate-600">
                  เลขมิเตอร์ (<span class="font-mono"><?php echo $previewData['waterPrev']; ?></span> ➔ <span class="font-mono"><?php echo $previewData['waterCurr']; ?></span>) = ใช้ไป <b><?php echo $previewData['waterUnits']; ?></b> หน่วย (ยูนิตละ <?php echo number_format($previewData['waterRate'], 2); ?> ฿)
                </td>
                <td class="p-3.5 text-right font-bold text-sky-700"><?php echo number_format($previewData['waterTotal'], 2); ?> ฿</td>
              </tr>
              <tr>
                <td class="p-3.5 font-bold text-amber-800">ค่าไฟฟ้า</td>
                <td class="p-3.5 text-slate-600">
                  เลขมิเตอร์ (<span class="font-mono"><?php echo $previewData['electricPrev']; ?></span> ➔ <span class="font-mono"><?php echo $previewData['electricCurr']; ?></span>) = ใช้ไป <b><?php echo $previewData['electricUnits']; ?></b> หน่วย (ยูนิตละ <?php echo number_format($previewData['electricRate'], 2); ?> ฿)
                </td>
                <td class="p-3.5 text-right font-bold text-amber-700"><?php echo number_format($previewData['electricTotal'], 2); ?> ฿</td>
              </tr>
              <?php if($previewData['otherFee'] > 0): ?>
              <tr>
                <td class="p-3.5 font-bold text-slate-800">ค่าใช้จ่ายอื่นๆ (ส่วนต่าง)</td>
                <td class="p-3.5 text-slate-600 font-medium">
                  <?php echo !empty($previewData['otherFeeNote']) ? htmlspecialchars($previewData['otherFeeNote']) : 'ค่าส่วนกลาง / ค่าบริการเสริมอื่นๆ'; ?>
                </td>
                <td class="p-3.5 text-right font-bold text-slate-900"><?php echo number_format($previewData['otherFee'], 2); ?> ฿</td>
              </tr>
              <?php endif; ?>
              <tr class="bg-blue-50/50 font-bold text-sm">
                <td colspan="2" class="p-4 text-right text-blue-900 font-display">ยอดชำระสุทธิทั้งสิ้น:</td>
                <td class="p-4 text-right text-blue-700 font-display text-base">฿<?php echo number_format($previewData['totalAmount'], 2); ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <form action="manage_invoice.php?tab=list" method="POST" class="mt-5 flex flex-wrap items-center gap-3">
          <?php foreach($previewData as $key => $val): ?>
            <input type="hidden" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($val); ?>">
          <?php endforeach; ?>
          <button type="submit" name="confirm_invoice" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs sm:text-sm font-bold shadow-md transition flex items-center gap-2 cursor-pointer">
            <i data-lucide="check-circle" class="w-4 h-4"></i> ยืนยันและออกใบแจ้งหนี้ส่งให้ผู้เช่า
          </button>
          <a href="manage_invoice.php?tab=create" class="px-5 py-3 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-xl text-xs sm:text-sm font-bold transition flex items-center gap-1.5">
            <i data-lucide="x" class="w-4 h-4"></i> ยกเลิก / กรอกใหม่
          </a>
        </form>
      </div>
      <?php else: ?>

      <!-- ขั้นตอนที่ 1: ฟอร์มกรอกข้อมูลออกบิล -->
      <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 md:p-8">
        <div class="flex items-center gap-3 pb-6 border-b border-slate-100">
          <div class="w-11 h-11 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0 border border-blue-100">
            <i data-lucide="edit-3" class="w-5 h-5"></i>
          </div>
          <div>
            <h3 class="font-display font-bold text-lg text-slate-800">+ ออกใบแจ้งหนี้รอบเดือนใหม่</h3>
            <p class="text-xs text-slate-500">เลือกห้องพักและระบุเลขมิเตอร์น้ำ-ไฟ ระบบจะคำนวณยอดเงินและแสดงพรีวิวให้ตรวจสอบก่อนส่งจริง</p>
          </div>
        </div>

        <form action="manage_invoice.php?tab=create" method="POST" id="invoiceForm" class="mt-6 space-y-6">
          
          <!-- แถวที่ 1: ห้องพัก และ รอบเดือน -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1.5">เลือกห้องพัก: <span class="text-rose-500">*</span></label>
              <div class="relative">
                <select name="roomId" id="roomSelect" required onchange="handleRoomChange()" class="w-full px-4 py-3 pr-10 bg-white border-2 border-stone-200 hover:border-rose-400 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/10 rounded-2xl text-xs sm:text-sm font-bold text-slate-800 shadow-xs outline-none transition appearance-none cursor-pointer">
                <option value="">-- เลือกห้องพัก (เฉพาะห้องที่มีผู้เช่า) --</option>
                <?php foreach($rooms as $r): ?>
                  <option value="<?php echo $r['roomId']; ?>" 
                          <?php echo ($presetRoomId == $r['roomId']) ? 'selected' : ''; ?>
                          data-room="<?php echo htmlspecialchars($r['roomNumber']); ?>"
                          data-rent="<?php echo $r['rentPrice']; ?>" 
                          data-tenant="<?php echo htmlspecialchars($r['fullName']); ?>"
                          data-water-prev="<?php echo $r['lastWater'] ?? ''; ?>"
                          data-electric-prev="<?php echo $r['lastElectric'] ?? ''; ?>"
                          data-last-month="<?php echo htmlspecialchars($r['lastBillingMonth'] ?? ''); ?>"
                          data-initial-water="<?php echo $r['initialWaterMeter'] ?? '0'; ?>"
                          data-initial-electric="<?php echo $r['initialElectricMeter'] ?? '0'; ?>"
                          data-lease-start="<?php echo !empty($r['leaseStartDate']) ? date('d/m/Y', strtotime($r['leaseStartDate'])) : ''; ?>"
                          data-parking-plan="<?php echo htmlspecialchars($r['parkingPlan'] ?? 'None'); ?>"
                          data-parking-fee="<?php echo (float)($r['parkingFee'] ?? 0); ?>"
                          data-internet-plan="<?php echo htmlspecialchars($r['internetPlan'] ?? 'None'); ?>"
                          data-internet-fee="<?php echo (float)($r['internetFee'] ?? 0); ?>"
                          data-vehicle-type="<?php echo htmlspecialchars($r['vehicleType'] ?? 'Car'); ?>"
                          data-parking-source="<?php echo htmlspecialchars($r['parkingSource'] ?? 'lease'); ?>">
                    ห้อง <?php echo $r['roomNumber']; ?> (<?php echo htmlspecialchars($r['fullName']); ?>) - ค่าเช่า ฿<?php echo number_format($r['rentPrice'], 2); ?>
                  </option>
                <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-rose-500">
                  <i data-lucide="chevron-down" class="w-4 h-4"></i>
                </div>
              </div>
            </div>

            <div>
              <div class="flex items-center justify-between mb-1.5">
                <label class="block text-xs font-bold text-slate-700">ประจำเดือน (รอบบิล): <span class="text-rose-500">*</span></label>
                <span class="text-[10px] text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-bold flex items-center gap-1">
                  <i data-lucide="calendar" class="w-3 h-3 text-emerald-500"></i> ปฏิทินเลือกรอบบิล (เลือกย้อนหลังได้ / ห้ามเลือกล่วงหน้า)
                </span>
              </div>
              <div class="relative">
                <input type="month" 
                       name="billingMonth" 
                       id="billingMonthInput" 
                       value="<?php echo !empty($presetMonth) ? htmlspecialchars($presetMonth) : date('Y-m'); ?>" 
                       max="<?php echo date('Y-m'); ?>" 
                       required 
                       onclick="this.showPicker && this.showPicker()"
                       onchange="handleBillingMonthChange()" 
                       class="w-full px-4 py-3 bg-white border-2 border-stone-200 hover:border-rose-400 focus:border-rose-500 rounded-2xl text-xs sm:text-sm font-bold text-slate-800 shadow-xs outline-none transition cursor-pointer">
              </div>
              <input type="hidden" name="presetReqId" id="presetReqId" value="<?php echo htmlspecialchars($presetReqId ?? ''); ?>">
            </div>
          </div>

          <!-- แถวที่ 2: ค่าน้ำประปา -->
          <div class="p-5 bg-sky-50/50 rounded-2xl border border-sky-100">
            <div class="flex items-center gap-2 mb-3">
              <i data-lucide="droplet" class="w-4 h-4 text-sky-600"></i>
              <span class="font-bold text-xs text-sky-900">มิเตอร์น้ำประปา</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <div class="flex items-center justify-between mb-1">
                  <label class="block text-[11px] font-bold text-slate-600">
                    มิเตอร์น้ำ (เลขเก่า): <span class="text-rose-500">*</span>
                  </label>
                  <span id="waterPrevSourceBadge" class="text-[10px] text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.2 rounded-full font-bold hidden">
                    ✓ ดึงอัตโนมัติ
                  </span>
                </div>
                <div class="relative">
                  <input type="number" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" name="waterPrev" id="waterPrev" required placeholder="เช่น 0 (4 หลัก)" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4); calculateLiveTotal();" class="w-full pl-3 pr-8 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-sky-500 outline-none font-mono font-medium text-slate-700">
                  <button type="button" onclick="toggleEditPrevMeter('waterPrev')" title="คลิกเพื่อแก้ไขเลขมิเตอร์เก่า" class="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] text-slate-400 hover:text-blue-600 p-1 rounded transition cursor-pointer">
                    <i data-lucide="pencil" class="w-3 h-3"></i>
                  </button>
                </div>
              </div>
              <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">
                  มิเตอร์น้ำ (เลขใหม่): <span class="text-rose-500">*</span>
                  <span class="text-[10px] text-sky-600 font-semibold ml-1">⬅ กรอกเลข 4 หลัก (0-9999)</span>
                </label>
                <input type="number" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" name="waterCurr" id="waterCurr" required placeholder="เลข 4 หลัก (เช่น 1234)" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4); calculateLiveTotal();" class="w-full px-3.5 py-2.5 bg-white border border-sky-300 ring-2 ring-sky-100 rounded-xl text-xs focus:ring-2 focus:ring-sky-500 outline-none font-mono font-bold text-slate-800 shadow-2xs">
              </div>
              <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1 flex items-center justify-between">
                  <span>ใช้ไป (หน่วย):</span>
                  <span id="waterCostPreview" class="text-[10px] text-sky-700 font-bold">฿0.00</span>
                </label>
                <div class="relative">
                  <input type="text" id="waterUnitsUsed" readonly value="0 หน่วย" class="w-full pl-3.5 pr-8 py-2.5 bg-sky-50/70 border border-sky-200/80 rounded-xl text-xs font-mono font-bold text-sky-950 outline-none select-none shadow-2xs">
                  <i data-lucide="calculator" class="w-3.5 h-3.5 text-slate-400 absolute right-2.5 top-1/2 -translate-y-1/2"></i>
                </div>
                <div class="mt-1.5 flex justify-end">
                  <span id="waterRateBadge" class="text-[11px] text-sky-800 bg-sky-100/90 border border-sky-200 px-2.5 py-0.5 rounded-full font-bold flex items-center gap-1 shadow-2xs">
                    <i data-lucide="lock" class="w-3 h-3 text-sky-600"></i> ค่าน้ำต่อหน่วย: 18.00 บาท
                  </span>
                </div>
                <input type="hidden" name="waterRate" id="waterRate" value="18.00">
              </div>
            </div>
          </div>

          <!-- แถวที่ 3: ค่าไฟฟ้า -->
          <div class="p-5 bg-amber-50/50 rounded-2xl border border-amber-100">
            <div class="flex items-center gap-2 mb-3">
              <i data-lucide="zap" class="w-4 h-4 text-amber-600"></i>
              <span class="font-bold text-xs text-amber-900">มิเตอร์ไฟฟ้า</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <div class="flex items-center justify-between mb-1">
                  <label class="block text-[11px] font-bold text-slate-600">
                    มิเตอร์ไฟ (เลขเก่า): <span class="text-rose-500">*</span>
                  </label>
                  <span id="electricPrevSourceBadge" class="text-[10px] text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.2 rounded-full font-bold hidden">
                    ✓ ดึงอัตโนมัติ
                  </span>
                </div>
                <div class="relative">
                  <input type="number" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" name="electricPrev" id="electricPrev" required placeholder="เช่น 0 (4 หลัก)" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4); calculateLiveTotal();" class="w-full pl-3 pr-8 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-amber-500 outline-none font-mono font-medium text-slate-700">
                  <button type="button" onclick="toggleEditPrevMeter('electricPrev')" title="คลิกเพื่อแก้ไขเลขมิเตอร์เก่า" class="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] text-slate-400 hover:text-blue-600 p-1 rounded transition cursor-pointer">
                    <i data-lucide="pencil" class="w-3 h-3"></i>
                  </button>
                </div>
              </div>
              <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">
                  มิเตอร์ไฟ (เลขใหม่): <span class="text-rose-500">*</span>
                  <span class="text-[10px] text-amber-600 font-semibold ml-1">⬅ กรอกเลข 4 หลัก (0-9999)</span>
                </label>
                <input type="number" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" name="electricCurr" id="electricCurr" required placeholder="เลข 4 หลัก (เช่น 5678)" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4); calculateLiveTotal();" class="w-full px-3.5 py-2.5 bg-white border border-amber-300 ring-2 ring-amber-100 rounded-xl text-xs focus:ring-2 focus:ring-amber-500 outline-none font-mono font-bold text-slate-800 shadow-2xs">
              </div>
              <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1 flex items-center justify-between">
                  <span>ใช้ไป (หน่วย):</span>
                  <span id="electricCostPreview" class="text-[10px] text-amber-700 font-bold">฿0.00</span>
                </label>
                <div class="relative">
                  <input type="text" id="electricUnitsUsed" readonly value="0 หน่วย" class="w-full pl-3.5 pr-8 py-2.5 bg-amber-50/70 border border-amber-200/80 rounded-xl text-xs font-mono font-bold text-amber-950 outline-none select-none shadow-2xs">
                  <i data-lucide="calculator" class="w-3.5 h-3.5 text-slate-400 absolute right-2.5 top-1/2 -translate-y-1/2"></i>
                </div>
                <div class="mt-1.5 flex justify-end">
                  <span id="electricRateBadge" class="text-[11px] text-amber-800 bg-amber-100/90 border border-amber-200 px-2.5 py-0.5 rounded-full font-bold flex items-center gap-1 shadow-2xs">
                    <i data-lucide="lock" class="w-3 h-3 text-amber-600"></i> ค่าไฟต่อหน่วย: 8.00 บาท
                  </span>
                </div>
                <input type="hidden" name="electricRate" id="electricRate" value="8.00">
              </div>
            </div>
          </div>

          <!-- แถวที่ 4: ค่าส่วนกลาง / ค่าส่วนต่างและบริการอื่นๆ -->
          <div class="p-5 bg-rose-50/40 rounded-2xl border border-indigo-100 relative" id="otherFeeDropdownGroup">
            <div class="flex items-center gap-2 mb-3">
              <i data-lucide="receipt" class="w-4 h-4 text-rose-600"></i>
              <span class="font-bold text-xs text-indigo-950">ค่าส่วนกลาง / ค่าส่วนต่างและบริการอื่นๆ</span>
              <span id="otherFeeSelectedCount" class="text-[11px] text-rose-700 bg-indigo-100/80 border border-rose-200/80 px-2.5 py-0.5 rounded-full ml-auto font-bold hidden">
                เลือก 0 รายการ
              </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
              <!-- Col 1 & 2: Dropdown Trigger & Selected Pills -->
              <div class="sm:col-span-2">
                <label class="block text-[11px] font-bold text-slate-600 mb-1 flex items-center justify-between">
                  <span>เลือกรายการค่าส่วนต่าง (มีติ๊กเลือก):</span>
                  <span class="text-[10px] text-rose-600 font-semibold">คลิกเพื่อเปิดรายการติ๊ก</span>
                </label>
                <button type="button" id="otherFeeToggleBtn" onclick="toggleOtherFeeDropdown()" class="w-full flex items-center justify-between px-3.5 py-2.5 bg-white hover:bg-slate-50 border border-rose-200/80 rounded-xl text-xs text-slate-700 transition cursor-pointer text-left shadow-2xs">
                  <span class="flex items-center gap-2 truncate" id="otherFeeBtnDisplay">
                    <i data-lucide="list-checks" class="w-4 h-4 text-rose-600 shrink-0"></i>
                    <span class="truncate font-medium">คลิกเลือกค่าส่วนต่าง (เช่น ค่าขยะ 20.-, ค่าส่วนกลาง 100.-)</span>
                  </span>
                  <i data-lucide="chevron-down" id="otherFeeChevron" class="w-4 h-4 text-slate-400 shrink-0 transition-transform duration-200 ml-2"></i>
                </button>

                <!-- Selected Items Pills Display -->
                <div id="otherFeePills" class="flex flex-wrap gap-1.5 mt-2 empty:hidden"></div>

                <!-- Hidden Note for Form Submission -->
                <input type="hidden" name="otherFeeNote" id="otherFeeNote" value="">
              </div>

              <!-- Col 3: Amount Box (ล็อกไม่ให้แก้ไขได้ ล็อกตามราคาที่คิดเลย) -->
              <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1 flex items-center justify-between">
                  <span>ยอดรวมค่าส่วนต่าง (บาท):</span>
                  <span class="text-[10px] text-emerald-700 bg-emerald-50 border border-emerald-200/90 px-2 py-0.5 rounded-full font-bold flex items-center gap-1 shadow-2xs">
                    <i data-lucide="lock" class="w-2.5 h-2.5 text-emerald-600"></i> ล็อกคำนวณอัตโนมัติ
                  </span>
                </label>
                <div class="relative">
                  <input type="number" step="0.01" name="otherFee" id="inputOtherFee" value="120.00" readonly class="w-full pl-3 pr-8 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-800 text-right cursor-not-allowed select-none shadow-2xs" placeholder="0.00" title="ยอดรวมค่าส่วนต่างล็อกตามรายการที่เลือก (คำนวณอัตโนมัติ)">
                  <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 font-bold select-none">฿</span>
                </div>
              </div>
            </div>

            <!-- Floating Dropdown Checklist Card -->
            <div id="otherFeeMenu" class="absolute left-4 right-4 sm:left-5 sm:right-auto sm:w-[480px] top-[95px] bg-white border border-slate-200 rounded-2xl shadow-2xl p-4 z-40 hidden transition-all space-y-3">
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                  <i data-lucide="check-square" class="w-3.5 h-3.5 text-blue-600"></i>
                  ติ๊กเลือกค่าใช้จ่ายส่วนต่าง
                </span>
                <button type="button" onclick="clearOtherFees()" class="text-[11px] text-rose-500 hover:text-rose-700 font-medium cursor-pointer" title="ล้างเฉพาะรายการที่เลือกเพิ่มเติม">
                  ล้างรายการที่เลือกเพิ่ม
                </button>
              </div>

              <!-- Context Notice Bar (ดึงสัญญาของห้อง) -->
              <div id="otherFeeRoomNotice" class="p-2.5 rounded-xl text-xs flex items-center justify-between border bg-slate-50 border-slate-200 text-slate-600 transition">
                <span class="flex items-center gap-1.5">
                  <i data-lucide="info" class="w-3.5 h-3.5 text-blue-500 shrink-0"></i>
                  <span>กรุณาเลือกห้องพักด้านบน ระบบจะดึง<b>ค่าที่จอดรถและค่าอินเทอร์เน็ตตามสัญญา</b>มาให้อัตโนมัติ</span>
                </span>
              </div>

              <!-- Checkbox List -->
              <div class="space-y-1.5 max-h-64 overflow-y-auto pr-1">
                <!-- ค่าบริการประจำเดือน & ตามสัญญา (ล็อกจ่ายทุกเดือน - ไม่มีช่องติ๊ก ล็อกตามราคาที่คิด) -->
                <div class="p-3 bg-gradient-to-br from-amber-50/80 via-orange-50/30 to-amber-50/80 rounded-2xl border border-amber-200/90 mb-2.5 shadow-2xs">
                  <div class="flex items-center justify-between text-[11px] font-bold text-amber-900 mb-2.5">
                    <span class="flex items-center gap-1.5">
                      <i data-lucide="lock" class="w-3.5 h-3.5 text-amber-600 shrink-0"></i>
                      <span>ค่าบริการประจำเดือน & ตามสัญญา (ล็อกจ่ายทุกเดือน)</span>
                    </span>
                    <span class="text-[10px] text-amber-800 font-bold bg-amber-100 border border-amber-300/80 px-2.5 py-0.5 rounded-full shadow-2xs flex items-center gap-1">
                      <i data-lucide="shield-check" class="w-3 h-3 text-amber-600"></i> ล็อกบังคับในบิล
                    </span>
                  </div>

                  <div class="space-y-1.5" id="lockedServicesContainer">
                    <!-- รายการล็อกจะถูกสร้างและแสดงผลอัตโนมัติตามสัญญาของห้องที่เลือก -->
                  </div>
                </div>

                <!-- ค่าบริการอื่นๆ เพิ่มเติม (เช็คลิสต์เลือกตามต้องการ ไม่ล็อก) -->
                <div class="pt-1.5 pb-1 flex items-center justify-between px-1" id="optionalFeesHeader">
                  <span class="text-[11px] font-bold text-slate-600 flex items-center gap-1.5">
                    <i data-lucide="list-checks" class="w-3.5 h-3.5 text-blue-600 shrink-0"></i>
                    ค่าบริการอื่นๆ (เลือกได้ตามต้องการ - ไม่ต้องจ่ายทุกเดือน)
                  </span>
                </div>

                <!-- ค่าที่จอดรถยนต์ (กรณีไม่มีในสัญญา) -->
                <label id="optParkingCarLabel" class="flex items-center justify-between p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-transparent hover:border-slate-200 transition">
                  <div class="flex items-center gap-2.5">
                    <input type="checkbox" id="cbOptCarPark" class="other-fee-checkbox w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 cursor-pointer" data-category="parking" data-name="ค่าที่จอดรถยนต์" data-amount="100" onchange="recalculateOtherFees()">
                    <span class="text-xs font-semibold text-slate-700 flex items-center gap-1.5"><i data-lucide="car" class="w-3.5 h-3.5 text-slate-500"></i> ค่าที่จอดรถยนต์</span>
                  </div>
                  <span class="text-xs font-mono font-bold text-slate-700 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-md">+100 ฿</span>
                </label>

                <!-- ค่าที่จอดมอเตอร์ไซค์ (กรณีไม่มีในสัญญา) -->
                <label id="optParkingMotoLabel" class="flex items-center justify-between p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-transparent hover:border-slate-200 transition">
                  <div class="flex items-center gap-2.5">
                    <input type="checkbox" id="cbOptMotoPark" class="other-fee-checkbox w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 cursor-pointer" data-category="parking" data-name="ค่าที่จอดมอเตอร์ไซค์" data-amount="100" onchange="recalculateOtherFees()">
                    <span class="text-xs font-semibold text-slate-700 flex items-center gap-1.5"><i data-lucide="bike" class="w-3.5 h-3.5 text-slate-500"></i> ค่าที่จอดมอเตอร์ไซค์</span>
                  </div>
                  <span class="text-xs font-mono font-bold text-slate-700 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-md">+100 ฿</span>
                </label>

                <!-- ค่าอินเทอร์เน็ต / Wi-Fi (กรณีไม่มีในสัญญา) -->
                <label id="optInternetLabel" class="flex items-center justify-between p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-transparent hover:border-slate-200 transition">
                  <div class="flex items-center gap-2.5">
                    <input type="checkbox" id="cbOptInternet" class="other-fee-checkbox w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 cursor-pointer" data-category="internet" data-name="ค่าอินเทอร์เน็ต / Wi-Fi" data-amount="150" onchange="recalculateOtherFees()">
                    <span class="text-xs font-semibold text-slate-700 flex items-center gap-1.5"><i data-lucide="wifi" class="w-3.5 h-3.5 text-slate-500"></i> ค่าอินเทอร์เน็ต / Wi-Fi</span>
                  </div>
                  <span class="text-xs font-mono font-bold text-slate-700 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-md">+150 ฿</span>
                </label>

                <!-- ค่าล้างแอร์ / บำรุงรักษา -->
                <label class="flex items-center justify-between p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-transparent hover:border-slate-200 transition">
                  <div class="flex items-center gap-2.5">
                    <input type="checkbox" class="other-fee-checkbox w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 cursor-pointer" data-name="ค่าล้างแอร์ / บำรุงรักษา" data-amount="300" onchange="recalculateOtherFees()">
                    <span class="text-xs font-semibold text-slate-700 flex items-center gap-1.5"><i data-lucide="wind" class="w-3.5 h-3.5 text-slate-500"></i> ค่าล้างแอร์ / บำรุงรักษา</span>
                  </div>
                  <span class="text-xs font-mono font-bold text-slate-700 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-md">+300 ฿</span>
                </label>

                <!-- ค่าปรับชำระล่าช้า -->
                <label class="flex items-center justify-between p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-transparent hover:border-slate-200 transition">
                  <div class="flex items-center gap-2.5">
                    <input type="checkbox" class="other-fee-checkbox w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 cursor-pointer" data-name="ค่าปรับชำระล่าช้า" data-amount="100" onchange="recalculateOtherFees()">
                    <span class="text-xs font-semibold text-slate-700 flex items-center gap-1.5"><i data-lucide="clock" class="w-3.5 h-3.5 text-slate-500"></i> ค่าปรับชำระล่าช้า</span>
                  </div>
                  <span class="text-xs font-mono font-bold text-slate-700 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-md">+100 ฿</span>
                </label>

                <!-- อื่นๆ กำหนดเอง -->
                <div class="p-2.5 rounded-xl bg-slate-50/80 border border-slate-200/80 mt-1.5">
                  <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-semibold text-slate-700">ค่าส่วนต่างอื่นๆ (กำหนดเอง):</span>
                    <span class="text-[10px] text-slate-400">ระบุเพิ่มได้</span>
                  </div>
                  <div class="grid grid-cols-2 gap-1.5">
                    <input type="text" id="customFeeName" placeholder="เช่น ค่าคีย์การ์ด" oninput="recalculateOtherFees()" class="px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs outline-none focus:ring-1 focus:ring-blue-500 text-slate-700">
                    <div class="relative">
                      <input type="number" step="0.01" id="customFeeAmount" placeholder="0.00" oninput="recalculateOtherFees()" class="w-full pl-2 pr-6 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-mono text-right outline-none focus:ring-1 focus:ring-rose-500 text-slate-800 font-bold">
                      <span class="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] text-slate-400 font-bold select-none">฿</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Footer with Done button -->
              <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                <span class="text-xs font-medium text-slate-600">
                  รวมค่าส่วนต่าง: <b id="otherFeePopupTotal" class="text-blue-600 font-mono text-sm">฿120.00</b>
                </span>
                <button type="button" onclick="toggleOtherFeeDropdown(false)" class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition cursor-pointer shadow-xs">
                  ตกลง / ปิด
                </button>
              </div>
            </div>
          </div>

          <!-- Live Total Calculation Preview Box -->
          <div class="p-4 bg-slate-50 border border-slate-200 rounded-2xl flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-2">
              <i data-lucide="calculator" class="w-4 h-4 text-blue-600"></i>
              <span class="text-xs text-slate-600 font-semibold">ยอดคำนวณเบื้องต้น (รวมค่าเช่า):</span>
            </div>
            <div class="text-lg font-display font-bold text-blue-600" id="liveEstimatedTotal">
              ฿0.00
            </div>
          </div>

          <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
            <button type="submit" name="preview_invoice" class="px-7 py-3.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-2xl text-xs sm:text-sm font-bold shadow-lg shadow-rose-500/25 hover:shadow-xl transition flex items-center gap-2 cursor-pointer">
              <i data-lucide="search-check" class="w-5 h-5"></i> ตรวจสอบความถูกต้องและออกบิล
            </button>
          </div>
        </form>
      </div>
      <?php endif; ?>

    </div>

    <!-- ========================================================================= -->
    <!-- 🌟 TAB 3: คำขอรอบเดือนบิลย้อนหลังจากผู้เช่า (อนุมัติ & ยกเลิกคำขอ) 🌟 -->
    <!-- ========================================================================= -->
    <div id="tab-content-retro" class="<?php echo ($activeTab === 'retro') ? '' : 'hidden'; ?> space-y-6">

      <div class="bg-white rounded-3xl border border-rose-200/60 p-6 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-rose-100 gap-3">
          <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-rose-500 to-pink-600 text-white flex items-center justify-center shadow-md shadow-rose-500/20 shrink-0">
              <i data-lucide="history" class="w-5 h-5"></i>
            </div>
            <div>
              <div class="flex items-center gap-2">
                <h3 class="font-display font-bold text-lg text-slate-800">คำขอรอบเดือนบิลย้อนหลังจากผู้เช่า</h3>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
                  <?php echo count($retroRequests); ?> คำขอ
                </span>
              </div>
              <p class="text-xs text-slate-500 mt-0.5">รายการคำขอที่ผู้เช่ายื่นขอบิลรอบเดือนย้อนหลัง ท่านสามารถกดอนุมัติเพื่อไปออกบิล หรือกดยกเลิกคำขอได้</p>
            </div>
          </div>

          <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200">
              <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
              รอการอนุมัติ: <?php echo $pendingRetroCount; ?> รายการ
            </span>
          </div>
        </div>

        <!-- ตารางรายการคำขอ -->
        <div class="mt-4 overflow-x-auto">
          <table class="w-full text-left text-xs sm:text-sm">
            <thead>
              <tr class="text-[11px] font-bold text-slate-500 uppercase tracking-wider border-b border-slate-100 bg-slate-50/70">
                <th class="py-3 px-4 rounded-l-xl">ห้องพัก</th>
                <th class="py-3 px-4">ผู้เช่า</th>
                <th class="py-3 px-4">ประเภทคำขอ</th>
                <th class="py-3 px-4">รอบเดือนที่ขอ</th>
                <th class="py-3 px-4">เหตุผล & เอกสารแนบ</th>
                <th class="py-3 px-4">บิลในระบบ</th>
                <th class="py-3 px-4">วันที่ยื่น</th>
                <th class="py-3 px-4 text-center">สถานะ</th>
                <th class="py-3 px-4">หมายเหตุแอดมิน</th>
                <th class="py-3 px-4 text-center rounded-r-xl">การจัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php if(empty($retroRequests)): ?>
              <tr>
                <td colspan="10" class="text-center py-12 text-slate-400">
                  <div class="flex flex-col items-center justify-center gap-2">
                    <i data-lucide="inbox" class="w-10 h-10 text-slate-300 stroke-1"></i>
                    <p class="text-sm font-semibold">ยังไม่มีคำขอรอบเดือนบิลย้อนหลังจากผู้เช่า</p>
                  </div>
                </td>
              </tr>
              <?php else: ?>
                <?php foreach($retroRequests as $req): 
                  $st = $req['status'] ?? 'Pending';
                  $reqType = $req['requestType'] ?? 'PaidInvoice';
                  // Format requested month in Thai
                  $reqMonthDisplay = $req['requestedMonth'];
                  if (preg_match('/^(\d{4})-(\d{2})$/', trim($req['requestedMonth']), $mMatches)) {
                    $thMonths = [
                      '01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.',
                      '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.',
                      '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.'
                    ];
                    $reqMonthDisplay = ($thMonths[$mMatches[2]] ?? '') . ' ' . $mMatches[1] . ' (' . $req['requestedMonth'] . ')';
                  }
                ?>
                <tr class="hover:bg-slate-50/70 transition">
                  <td class="py-3 px-4 font-bold text-slate-800">
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-rose-50 text-rose-700 font-bold border border-rose-200">
                      ห้อง <?php echo htmlspecialchars($req['roomNumber']); ?>
                    </span>
                  </td>
                  <td class="py-3 px-4">
                    <div class="font-bold text-slate-800"><?php echo htmlspecialchars($req['tenantName']); ?></div>
                    <?php if(!empty($req['tenantPhone'])): ?>
                      <div class="text-[11px] text-slate-400"><?php echo htmlspecialchars($req['tenantPhone']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="py-3 px-4 whitespace-nowrap">
                    <?php if($reqType === 'PaidInvoice'): ?>
                      <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-sky-50 text-sky-700 font-bold border border-sky-200 text-xs">
                        <i data-lucide="receipt" class="w-3.5 h-3.5 text-sky-600"></i> บิล/ใบเสร็จที่เคยจ่าย
                      </span>
                    <?php else: ?>
                      <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-rose-50 text-rose-700 font-bold border border-rose-200 text-xs">
                        <i data-lucide="file-plus" class="w-3.5 h-3.5 text-rose-600"></i> ขอให้ออกบิลใหม่
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="py-3 px-4 font-bold text-slate-900 font-mono">
                    <?php echo htmlspecialchars($reqMonthDisplay); ?>
                  </td>
                  <td class="py-3 px-4 text-slate-600 max-w-xs">
                    <div class="bg-slate-50 p-2 rounded-xl border border-slate-100 text-xs">
                      <?php echo nl2br(htmlspecialchars($req['reason'] ?: 'ไม่ได้ระบุเหตุผล')); ?>
                    </div>
                    <?php if(!empty($req['slipImage'])): ?>
                      <div class="mt-1">
                        <button type="button" onclick="openSlipPreviewModal('<?php echo htmlspecialchars($req['slipImage']); ?>')" class="text-rose-600 hover:text-rose-800 font-bold text-[11px] inline-flex items-center gap-1 cursor-pointer">
                          <i data-lucide="image" class="w-3 h-3"></i> ดูเอกสารแนบ
                        </button>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="py-3 px-4 whitespace-nowrap">
                    <?php if(!empty($req['matchedInvoiceId'])): ?>
                      <div class="flex items-center gap-1.5">
                        <span class="font-mono font-bold text-slate-800 text-xs">#INV-<?php echo str_pad($req['matchedInvoiceId'], 5, '0', STR_PAD_LEFT); ?></span>
                        <?php if(($req['matchedInvoiceStatus'] ?? '') === 'Paid'): ?>
                          <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">จ่ายแล้ว</span>
                        <?php else: ?>
                          <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">รอชำระ</span>
                        <?php endif; ?>
                      </div>
                      <div class="text-[11px] text-slate-500 font-mono mt-0.5">฿<?php echo number_format($req['matchedTotalAmount'] ?? 0, 2); ?></div>
                    <?php else: ?>
                      <span class="text-xs text-slate-400 italic">ยังไม่มีในระบบ</span>
                    <?php endif; ?>
                  </td>
                  <td class="py-3 px-4 text-xs text-slate-500 whitespace-nowrap">
                    <?php echo !empty($req['createdAt']) ? date('d/m/Y H:i', strtotime($req['createdAt'])) : '-'; ?>
                  </td>
                  <td class="py-3 px-4 text-center whitespace-nowrap">
                    <?php if($st === 'Pending'): ?>
                      <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span> รออนุมัติ
                      </span>
                    <?php elseif($st === 'Approved'): ?>
                      <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> อนุมัติแล้ว
                      </span>
                    <?php elseif($st === 'Rejected'): ?>
                      <?php if(($req['adminRemark'] ?? '') === 'ผู้เช่ายกเลิกคำขอด้วยตนเอง'): ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                          <i data-lucide="user-x" class="w-3.5 h-3.5 text-slate-500"></i> ผู้เช่ายกเลิกเอง
                        </span>
                      <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
                          <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> แอดมินยกเลิก
                        </span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td class="py-3 px-4 text-xs text-slate-500 max-w-[160px] truncate">
                    <?php echo htmlspecialchars($req['adminRemark'] ?? '-'); ?>
                  </td>
                  <td class="py-3 px-4 text-center whitespace-nowrap">
                    <div class="inline-flex items-center justify-center gap-1.5">
                      <?php if($st === 'Pending'): ?>
                        <!-- ฟอร์มกดอนุมัติสำหรับบิลที่เคยชำระแล้ว -->
                        <form action="manage_invoice.php?tab=retro" method="POST" class="m-0" onsubmit="return confirm('ยืนยันอนุมัติคำขอบิลย้อนหลังรอบเดือน <?php echo htmlspecialchars($req['requestedMonth']); ?> ห้อง <?php echo htmlspecialchars($req['roomNumber']); ?>?');">
                          <input type="hidden" name="requestId" value="<?php echo $req['requestId']; ?>">
                          <button type="submit" name="approve_retro_request" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-2xs transition flex items-center gap-1 cursor-pointer" title="อนุมัติส่งบิล">
                            <i data-lucide="check" class="w-3.5 h-3.5"></i> อนุมัติส่งบิล
                          </button>
                        </form>

                        <!-- ปุ่มยกเลิกคำขอ -->
                        <button type="button" onclick="openRejectRetroModal(<?php echo $req['requestId']; ?>, '<?php echo htmlspecialchars($req['roomNumber']); ?>', '<?php echo htmlspecialchars($req['tenantName']); ?>', '<?php echo htmlspecialchars($req['requestedMonth']); ?>')" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 rounded-xl text-xs font-bold transition flex items-center gap-1 cursor-pointer" title="ยกเลิกคำขอ">
                          <i data-lucide="x" class="w-3.5 h-3.5"></i> ยกเลิก
                        </button>

                      <?php elseif($st === 'Approved'): ?>
                        <?php if(!empty($req['matchedInvoiceId'])): ?>
                          <button type="button" onclick="openInvoiceDetailModal(<?php echo $req['matchedInvoiceId']; ?>)" class="px-3 py-1.5 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-xl text-xs font-bold transition flex items-center gap-1 shadow-2xs cursor-pointer">
                            <i data-lucide="eye" class="w-3.5 h-3.5 text-emerald-600"></i> ดูบิล
                          </button>
                          <?php if(($req['matchedInvoiceStatus'] ?? '') === 'Paid'): ?>
                            <a href="print_receipt.php?id=<?php echo $req['matchedInvoiceId']; ?>" target="_blank" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1 shadow-2xs cursor-pointer">
                              <i data-lucide="printer" class="w-3.5 h-3.5"></i> ใบเสร็จ
                            </a>
                          <?php endif; ?>
                        <?php else: ?>
                          <a href="manage_invoice.php?tab=create&roomId=<?php echo $req['roomId']; ?>&presetMonth=<?php echo urlencode($req['requestedMonth']); ?>&reqId=<?php echo $req['requestId']; ?>" class="px-3 py-1.5 bg-gradient-to-r from-rose-600 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-1 cursor-pointer" title="ออกใบแจ้งหนี้รอบนี้ทันที">
                            <i data-lucide="file-plus" class="w-3.5 h-3.5"></i> ออกบิลรอบนี้
                          </a>
                        <?php endif; ?>

                        <!-- ปุ่มยกเลิกคำขอหากเปลี่ยนใจ -->
                        <button type="button" onclick="openRejectRetroModal(<?php echo $req['requestId']; ?>, '<?php echo htmlspecialchars($req['roomNumber']); ?>', '<?php echo htmlspecialchars($req['tenantName']); ?>', '<?php echo htmlspecialchars($req['requestedMonth']); ?>')" class="px-2 py-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl text-xs font-semibold transition cursor-pointer" title="เปลี่ยนเป็นยกเลิกคำขอ">
                          <i data-lucide="x" class="w-3.5 h-3.5"></i>
                        </button>

                      <?php elseif($st === 'Rejected'): ?>
                        <!-- ฟอร์มเปลี่ยนเป็นอนุมัติหากต้องการ -->
                        <form action="manage_invoice.php?tab=retro" method="POST" class="m-0" onsubmit="return confirm('ต้องการเปลี่ยนสถานะกลับเป็นอนุมัติคำขอนี้หรือไม่?');">
                          <input type="hidden" name="requestId" value="<?php echo $req['requestId']; ?>">
                          <button type="submit" name="approve_retro_request" class="px-3 py-1.5 bg-slate-100 hover:bg-emerald-50 text-slate-600 hover:text-emerald-700 rounded-xl text-xs font-bold transition flex items-center gap-1 cursor-pointer">
                            <i data-lucide="refresh-cw" class="w-3 h-3"></i> คืนสถานะอนุมัติ
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

  </main>

    <!-- 🌟 FULLSCREEN EXPANSIVE MODAL: รายละเอียดใบแจ้งหนี้ฉบับเต็ม & ตรวจสอบสลิป 🌟 -->
  <div id="invoiceDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-md flex items-center justify-center p-0 sm:p-2 md:p-4 overflow-y-auto" onclick="closeInvoiceDetailModal()">
    <div id="invoiceModalDialog" class="modal-enter relative w-full h-full sm:h-[97vh] max-w-none sm:max-w-[96vw] 2xl:max-w-[94vw] bg-white rounded-none sm:rounded-3xl shadow-2xl flex flex-col border border-slate-200 overflow-hidden transition-all duration-300" onclick="event.stopPropagation()">
      
      <!-- Modal Header with Rose/Pink Accent -->
      <div class="px-6 py-4 border-b border-rose-100 flex items-center justify-between bg-gradient-to-r from-rose-50/90 via-pink-50/70 to-rose-50/90 shrink-0">
        <div class="flex items-center gap-3">
          <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-rose-500 to-pink-600 text-white flex items-center justify-center shadow-md shadow-rose-500/20">
            <i data-lucide="file-spreadsheet" class="w-5 h-5"></i>
          </div>
          <div>
            <div class="flex items-center gap-2">
              <h3 class="font-display font-bold text-base sm:text-lg text-slate-800" id="invDetailTitle">รายละเอียดใบแจ้งหนี้ #INV--</h3>
              <span id="invDetailStatusBadge" class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                รอชำระเงิน
              </span>
            </div>
            <p class="text-xs text-slate-500 mt-0.5" id="invDetailSubtitle">รอบเดือน -- • ห้อง -- • ผู้เช่า --</p>
          </div>
        </div>
        
        <!-- Right actions: Fullscreen toggle + Close button -->
        <div class="flex items-center gap-2">
          <button type="button" onclick="closeInvoiceDetailModal()" class="p-2 text-slate-400 hover:text-rose-600 hover:bg-white/80 rounded-xl transition cursor-pointer" title="ปิดหน้าต่าง">
            <i data-lucide="x" class="w-5 h-5"></i>
          </button>
        </div>
      </div>

      <!-- Modal Body: 2-Column Responsive Layout -->
      <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-slate-50/60">
        <div class="max-w-7xl mx-auto space-y-6">
          
          <!-- Summary Quick Cards -->
          <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
              <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                <i data-lucide="home" class="w-3.5 h-3.5 text-rose-500"></i> ห้องพัก / ผู้เช่า
              </div>
              <div class="text-sm font-bold text-slate-800" id="invQuickRoom">ห้อง --</div>
              <div class="text-xs text-slate-500 truncate" id="invQuickTenant">--</div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
              <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                <i data-lucide="calendar" class="w-3.5 h-3.5 text-amber-500"></i> รอบเดือนบิล
              </div>
              <div class="text-sm font-bold text-amber-700 font-mono" id="invQuickMonth">--</div>
              <div class="text-xs text-slate-400" id="invQuickCreatedAt">ออกบิล: --</div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
              <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                <i data-lucide="badge-check" class="w-3.5 h-3.5 text-blue-500"></i> สถานะการชำระ
              </div>
              <div class="text-sm font-bold" id="invQuickStatusText">--</div>
              <div class="text-xs text-slate-400" id="invQuickSlipState">--</div>
            </div>

            <div class="bg-gradient-to-br from-rose-500 via-rose-600 to-pink-600 p-4 rounded-2xl text-white shadow-md shadow-rose-500/20">
              <div class="text-[11px] font-bold text-rose-100 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                <i data-lucide="receipt" class="w-3.5 h-3.5 text-rose-200"></i> ยอดสุทธิทั้งสิ้น
              </div>
              <div class="text-xl sm:text-2xl font-black font-display tracking-tight" id="invQuickGrandTotal">฿0.00</div>
              <div class="text-[10px] text-rose-100/90 mt-0.5">รวมค่าเช่า + ค่าน้ำ + ค่าไฟ + อื่นๆ</div>
            </div>
          </div>

          <!-- Main 2-Column Section -->
          <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- Left Column (col-span-12 lg:col-span-7): Detailed Breakdown Table & Tenant Info -->
            <div class="lg:col-span-7 space-y-6">
              
              <!-- Card: Breakdown Details -->
              <div class="bg-white rounded-3xl border border-slate-200 shadow-xs overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center">
                      <i data-lucide="list-checks" class="w-4 h-4"></i>
                    </div>
                    <h4 class="font-display font-bold text-sm text-slate-800">ตารางแจกแจงรายการค่าใช้จ่าย</h4>
                  </div>
                  <span class="text-xs font-mono font-bold text-slate-400">อัตราค่าบริการมาตรฐาน</span>
                </div>

                <div class="overflow-x-auto">
                  <table class="w-full text-left text-xs border-collapse">
                    <thead>
                      <tr class="bg-slate-50/60 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                        <th class="py-3 px-5">รายการค่าใช้จ่าย</th>
                        <th class="py-3 px-4">รายละเอียดการคำนวณ / เลขมิเตอร์</th>
                        <th class="py-3 px-5 text-right">จำนวนเงิน (บาท)</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                      <!-- 1. ค่าเช่าห้อง -->
                      <tr class="hover:bg-slate-50/60 transition">
                        <td class="py-3.5 px-5">
                          <div class="font-bold text-slate-800 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                            ค่าเช่าห้องพักประจำเดือน
                          </div>
                          <div class="text-[11px] text-slate-400 pl-4">ตามสัญญาเช่าห้องพัก</div>
                        </td>
                        <td class="py-3.5 px-4 text-slate-600 font-medium">
                          อัตราคงที่ตามสัญญา
                        </td>
                        <td class="py-3.5 px-5 text-right font-bold text-slate-900 font-mono text-sm" id="invRowRent">
                          0.00 ฿
                        </td>
                      </tr>

                      <!-- 2. ค่าน้ำประปา -->
                      <tr class="hover:bg-slate-50/60 transition bg-sky-50/20">
                        <td class="py-3.5 px-5">
                          <div class="font-bold text-sky-800 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-sky-500"></span>
                            ค่าน้ำประปา
                          </div>
                          <div class="text-[11px] text-sky-600 pl-4" id="invWaterRateText">หน่วยละ 18.00 บาท</div>
                        </td>
                        <td class="py-3.5 px-4 text-slate-700 font-medium" id="invWaterCalcDetails">
                          มิเตอร์ 0 ➔ 0 (ใช้ไป 0 หน่วย)
                        </td>
                        <td class="py-3.5 px-5 text-right font-bold text-sky-700 font-mono text-sm" id="invRowWater">
                          0.00 ฿
                        </td>
                      </tr>

                      <!-- 3. ค่าไฟฟ้า -->
                      <tr class="hover:bg-slate-50/60 transition bg-amber-50/20">
                        <td class="py-3.5 px-5">
                          <div class="font-bold text-amber-800 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            ค่ากระแสไฟฟ้า
                          </div>
                          <div class="text-[11px] text-amber-600 pl-4" id="invElectricRateText">หน่วยละ 8.00 บาท</div>
                        </td>
                        <td class="py-3.5 px-4 text-slate-700 font-medium" id="invElectricCalcDetails">
                          มิเตอร์ 0 ➔ 0 (ใช้ไป 0 หน่วย)
                        </td>
                        <td class="py-3.5 px-5 text-right font-bold text-amber-700 font-mono text-sm" id="invRowElectric">
                          0.00 ฿
                        </td>
                      </tr>

                      <!-- 4. ค่าใช้จ่ายอื่นๆ / ค่าส่วนต่าง -->
                      <tr class="hover:bg-slate-50/60 transition">
                        <td class="py-3.5 px-5">
                          <div class="font-bold text-slate-800 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                            ค่าใช้จ่ายอื่นๆ / ค่าส่วนต่าง
                          </div>
                          <div class="text-[11px] text-slate-400 pl-4">ค่าขยะ, ค่าส่วนกลาง หรือส่วนลด</div>
                        </td>
                        <td class="py-3.5 px-4 text-slate-600 font-medium">
                          <span id="invOtherFeeNoteText">ตามที่ระบุในรอบบิล</span>
                        </td>
                        <td class="py-3.5 px-5 text-right font-bold text-slate-900 font-mono text-sm" id="invRowOther">
                          0.00 ฿
                        </td>
                      </tr>
                    </tbody>
                    <!-- Total Footer -->
                    <tfoot>
                      <tr class="bg-gradient-to-r from-rose-50 to-pink-50 border-t-2 border-rose-200/80">
                        <td colspan="2" class="py-4 px-5 text-right font-bold text-slate-700 text-sm">
                          ยอดรวมสุทธิทั้งสิ้น (Grand Total):
                        </td>
                        <td class="py-4 px-5 text-right font-black text-rose-600 font-mono text-lg" id="invRowGrandTotal">
                          0.00 ฿
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>

              <!-- Card: ข้อมูลผู้เช่า & การติดต่อ -->
              <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-5">
                <h5 class="text-xs font-bold text-slate-700 mb-3 flex items-center gap-2">
                  <i data-lucide="user" class="w-4 h-4 text-rose-500"></i> ข้อมูลผู้เช่าห้องพัก
                </h5>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                  <div class="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                    <span class="text-slate-400 block text-[10px]">ชื่อ-นามสกุล</span>
                    <strong class="text-slate-800 font-bold block mt-0.5" id="invTenantName">--</strong>
                  </div>
                  <div class="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                    <span class="text-slate-400 block text-[10px]">เบอร์โทรศัพท์</span>
                    <strong class="text-slate-800 font-bold block mt-0.5 font-mono" id="invTenantPhone">--</strong>
                  </div>
                  <div class="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                    <span class="text-slate-400 block text-[10px]">อีเมลติดต่อ</span>
                    <strong class="text-slate-800 font-bold block mt-0.5 truncate" id="invTenantEmail">--</strong>
                  </div>
                </div>
              </div>

            </div>

            <!-- Right Column (col-span-12 lg:col-span-5): Slip Image & Management Actions -->
            <div class="lg:col-span-5 space-y-6">
              
              <!-- Card: หลักฐานการโอนเงิน (สลิป) -->
              <div class="bg-white rounded-3xl border border-slate-200 shadow-xs overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center">
                      <i data-lucide="image" class="w-4 h-4"></i>
                    </div>
                    <h4 class="font-display font-bold text-sm text-slate-800">หลักฐานสลิปโอนเงิน</h4>
                  </div>
                  <span id="invSlipBadge" class="text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600">
                    ตรวจสลิป
                  </span>
                </div>

                <div class="p-5 flex flex-col items-center justify-center">
                  <!-- With Slip Image Box -->
                  <div id="invSlipWithImageBox" class="w-full flex flex-col items-center gap-3">
                    <div class="relative w-full flex items-center justify-center bg-slate-900/5 rounded-2xl p-3 border border-slate-200 group">
                      <img id="invDetailSlipImage" src="" alt="สลิปโอนเงิน" class="max-h-[48vh] max-w-full rounded-xl object-contain shadow-md transition hover:scale-102 cursor-pointer" onclick="openSlipFullWindow()">
                      <div class="absolute bottom-4 right-4 bg-slate-900/80 backdrop-blur-xs text-white text-[11px] px-3 py-1.5 rounded-xl flex items-center gap-1.5 pointer-events-none opacity-80 group-hover:opacity-100 transition shadow-sm">
                        <i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> คลิกขยายภาพ
                      </div>
                    </div>
                    <div class="w-full flex items-center justify-between gap-2">
                      <button type="button" onclick="openSlipFullWindow()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer">
                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i> เปิดภาพเต็มจอ
                      </button>
                      <a id="invSlipDownloadLink" href="" download class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                        <i data-lucide="download" class="w-3.5 h-3.5"></i> ดาวน์โหลดสลิป
                      </a>
                    </div>
                  </div>

                  <!-- No Slip Box -->
                  <div id="invSlipNoImageBox" class="w-full py-12 px-6 flex flex-col items-center justify-center text-center bg-amber-50/50 rounded-2xl border border-amber-200/70">
                    <div class="w-14 h-14 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center mb-3">
                      <i data-lucide="image-off" class="w-7 h-7"></i>
                    </div>
                    <h5 class="text-sm font-bold text-amber-900 mb-1">ยังไม่มีหลักฐานการโอนเงิน (ไม่มีสลิป)</h5>
                    <p class="text-xs text-amber-700/80 max-w-xs">ผู้เช่ายังไม่ได้แนบสลิปผ่านระบบ หรือชำระด้วยเงินสดโดยตรงกับนิติบุคคล</p>
                  </div>
                </div>
              </div>

              <!-- Card: จัดการสถานะและการพิมพ์ใบเสร็จ -->
              <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 space-y-4">
                <h4 class="font-display font-bold text-sm text-slate-800 flex items-center gap-2">
                  <i data-lucide="sliders" class="w-4 h-4 text-rose-500"></i> การดำเนินการสำหรับแอดมิน
                </h4>

                <div id="invDetailActionArea" class="space-y-3">
                  <!-- Action forms injected dynamically by JS -->
                </div>

                <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                  <a id="invPrintReceiptBtn" href="print_receipt.php" target="_blank" class="px-4 py-2.5 bg-sky-50 hover:bg-sky-100 text-sky-700 border border-sky-200 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                    <i data-lucide="printer" class="w-4 h-4"></i> พิมพ์ใบเสร็จรับเงิน
                  </a>
                  <button type="button" onclick="closeInvoiceDetailModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer">
                    ปิดหน้าต่าง
                  </button>
                </div>
              </div>

            </div>

          </div>

        </div>
      </div>

    </div>
  </div>

  <!-- Modal ปฏิเสธสลิป / ส่งกลับให้ลูกบ้านแนบใหม่ -->
  <div id="rejectSlipModal" class="hidden fixed inset-0 flex items-center justify-center p-3 sm:p-4 overflow-y-auto bg-slate-900/80 backdrop-blur-sm" style="z-index: 99999;" onclick="closeRejectSlipModal()">
    <div class="modal-enter relative w-full max-w-lg bg-white rounded-3xl shadow-2xl border border-rose-100 overflow-hidden" onclick="event.stopPropagation()">
      <div class="px-6 py-4 border-b border-rose-100 bg-gradient-to-r from-rose-50 via-pink-50 to-rose-50 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-rose-500 text-white flex items-center justify-center shadow-md shadow-rose-500/20">
            <i data-lucide="x-circle" class="w-5 h-5"></i>
          </div>
          <div>
            <h3 class="font-display font-bold text-base text-slate-800">ปฏิเสธสลิป / ส่งกลับให้แนบใหม่</h3>
            <p class="text-xs text-slate-500" id="rejectSlipModalSubtitle">บิล #INV-- • ห้อง --</p>
          </div>
        </div>
        <button type="button" onclick="closeRejectSlipModal()" class="p-2 text-slate-400 hover:text-slate-600 rounded-xl transition cursor-pointer">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form action="manage_invoice.php?tab=list" method="POST" class="p-6 space-y-4 m-0" onsubmit="return validateRejectSlipForm()">
        <input type="hidden" name="reject_slip" value="1">
        <input type="hidden" name="invoiceId" id="rejectSlipInvoiceId" value="">

        <!-- Slip Preview in Reject Modal -->
        <div id="rejectSlipPreviewBox" class="p-3 bg-slate-50 rounded-2xl border border-slate-200 flex items-center gap-3">
          <img id="rejectSlipPreviewImg" src="" alt="สลิปที่จะปฏิเสธ" class="w-16 h-16 rounded-xl object-contain bg-slate-900/10 border border-slate-300 shrink-0 cursor-pointer" onclick="openRejectSlipFullImage()">
          <div class="flex-1 min-w-0 text-xs">
            <div class="font-bold text-slate-700 truncate" id="rejectSlipPreviewTenant">ผู้เช่า: --</div>
            <div class="text-slate-500 mt-0.5" id="rejectSlipPreviewTotal">ยอดบิล: -- บาท</div>
            <button type="button" onclick="openRejectSlipFullImage()" class="mt-1 text-rose-600 hover:text-rose-700 font-bold inline-flex items-center gap-1 cursor-pointer text-[11px]">
              <i data-lucide="zoom-in" class="w-3 h-3"></i> คลิกดูภาพสลิปขนาดเต็ม
            </button>
          </div>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1.5">
            เลือกเหตุผลที่ปฏิเสธ (คลิกเพื่อใส่ข้อความรวดเร็ว):
          </label>
          <div class="flex flex-wrap gap-1.5 mb-2.5">
            <button type="button" onclick="setRejectReason('ยอดเงินในสลิปไม่ตรงกับยอดในใบแจ้งหนี้')" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-xs font-medium transition cursor-pointer">
              ยอดเงินไม่ตรง
            </button>
            <button type="button" onclick="setRejectReason('ภาพสลิปเบลอ หรือมองไม่เห็นรายละเอียดชัดเจน')" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-xs font-medium transition cursor-pointer">
              สลิปเบลอ/ไม่ชัด
            </button>
            <button type="button" onclick="setRejectReason('สลิปซ้ำ หรือเป็นสลิปของรอบเดือนเก่า')" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-xs font-medium transition cursor-pointer">
              สลิปซ้ำ/สลิปเก่า
            </button>
            <button type="button" onclick="setRejectReason('โอนผิดบัญชี หรือปลายทางไม่ถูกต้อง')" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-xs font-medium transition cursor-pointer">
              โอนผิดบัญชี
            </button>
            <button type="button" onclick="setRejectReason('ไม่พบยอดเงินเข้าบัญชีตามวันเวลาในสลิป')" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-xs font-medium transition cursor-pointer">
              ไม่พบยอดเงินเข้า
            </button>
          </div>

          <label for="rejectReasonInput" class="block text-xs font-bold text-slate-700 mb-1">
            ข้อความระบุเหตุผลและคำแนะนำถึงผู้เช่า <span class="text-rose-500">*</span>:
          </label>
          <textarea id="rejectReasonInput" name="rejectReason" rows="3" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 transition" placeholder="ระบุเหตุผลที่ปฏิเสธ เพื่อให้ผู้เช่าทราบและแนบสลิปใหม่ให้ถูกต้อง..."></textarea>
          <p class="text-[11px] text-slate-400 mt-1">ข้อความนี้จะแสดงในหน้าบิลของผู้เช่า และส่งแจ้งเตือนไปยังอีเมลของผู้เช่าโดยอัตโนมัติ</p>
        </div>

        <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
          <button type="button" onclick="closeRejectSlipModal()" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer">
            ยกเลิก
          </button>
          <button type="submit" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs sm:text-sm font-bold shadow-md shadow-rose-600/20 transition flex items-center gap-1.5 cursor-pointer">
            <i data-lucide="send" class="w-4 h-4"></i> ยืนยันปฏิเสธและส่งกลับ
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // เริ่มต้นไอคอน Lucide
    lucide.createIcons();

    // บังคับจำกัดเลขมิเตอร์น้ำและไฟให้กรอกได้ไม่เกิน 4 หลัก (0-9999)
    ['waterPrev', 'waterCurr', 'electricPrev', 'electricCurr'].forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener('input', function() {
          if (this.value.length > 4) {
            this.value = this.value.slice(0, 4);
          }
        });
      }
    });

    // ฟังก์ชันสลับแท็บ
    function switchTab(tab) {
      const listContent = document.getElementById('tab-content-list');
      const createContent = document.getElementById('tab-content-create');
      const retroContent = document.getElementById('tab-content-retro');
      const btnList = document.getElementById('btn-tab-list');
      const btnCreate = document.getElementById('btn-tab-create');
      const btnRetro = document.getElementById('btn-tab-retro');

      if (listContent) listContent.classList.add('hidden');
      if (createContent) createContent.classList.add('hidden');
      if (retroContent) retroContent.classList.add('hidden');

      const activeClass = "flex-1 sm:flex-none px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 text-white shadow-md shadow-rose-500/20";
      const inactiveClass = "flex-1 sm:flex-none px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer text-slate-600 hover:text-slate-900 hover:bg-slate-100/80";

      if (btnList) btnList.className = inactiveClass;
      if (btnCreate) btnCreate.className = inactiveClass;
      if (btnRetro) btnRetro.className = inactiveClass;

      if (tab === 'list') {
        if (listContent) listContent.classList.remove('hidden');
        if (btnList) btnList.className = activeClass;
      } else if (tab === 'create') {
        if (createContent) createContent.classList.remove('hidden');
        if (btnCreate) btnCreate.className = activeClass;
      } else if (tab === 'retro') {
        if (retroContent) retroContent.classList.remove('hidden');
        if (btnRetro) btnRetro.className = activeClass;
      }

      const url = new URL(window.location);
      url.searchParams.set('tab', tab);
      window.history.replaceState({}, '', url);
      lucide.createIcons();
    }

    // ฟิลเตอร์สถานะบิลในตาราง
    let currentFilter = 'all';

    function setFilterStatus(status) {
      currentFilter = status;
      
      document.querySelectorAll('.filter-btn').forEach(btn => {
        if (btn.getAttribute('data-filter') === status) {
          btn.classList.remove('bg-slate-100', 'text-slate-600');
          btn.classList.add('bg-slate-800', 'text-white');
        } else {
          btn.classList.remove('bg-slate-800', 'text-white');
          btn.classList.add('bg-slate-100', 'text-slate-600');
        }
      });

      filterInvoices();
    }

    function filterInvoices() {
      const searchVal = document.getElementById('invoiceSearch').value.toLowerCase().trim();
      const rows = document.querySelectorAll('.invoice-row');
      let visibleCount = 0;

      rows.forEach(row => {
        const rowStatus = row.getAttribute('data-status');
        const rowSearch = row.getAttribute('data-search');

        const matchStatus = (currentFilter === 'all' || rowStatus === currentFilter);
        const matchSearch = (!searchVal || rowSearch.includes(searchVal));

        if (matchStatus && matchSearch) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      document.getElementById('rowCountDisplay').innerText = `แสดง ${visibleCount} รายการ`;
    }

        // ข้อมูลใบแจ้งหนี้ทั้งหมดสำหรับเปิดดูรายละเอียดแบบเต็มจอทันที
    const allInvoicesData = <?php echo json_encode($invoices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    const invoiceMap = {};
    if (Array.isArray(allInvoicesData)) {
      allInvoicesData.forEach(inv => {
        invoiceMap[inv.invoiceId] = inv;
      });
    }

    let currentModalSlipUrl = '';
    let isInvoiceFullscreen = false;

    // เปิด Modal รายละเอียดใบแจ้งหนี้จอใหญ่
    function openInvoiceDetailModal(invId) {
      const inv = invoiceMap[invId];
      if (!inv) return;

      // Title & Subtitle
      document.getElementById('invDetailTitle').innerText = `รายละเอียดใบแจ้งหนี้ #INV-${inv.invoiceId}`;
      document.getElementById('invDetailSubtitle').innerText = `รอบเดือน ${inv.billingMonth} • ห้อง ${inv.roomNumber} • ผู้เช่า ${inv.fullName}`;

      // Status Badge
      const stBadge = document.getElementById('invDetailStatusBadge');
      const quickStatus = document.getElementById('invQuickStatusText');
      const quickSlip = document.getElementById('invQuickSlipState');
      if (inv.status === 'Paid') {
        stBadge.className = 'inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200';
        stBadge.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> ชำระแล้ว (Paid)';
        quickStatus.className = 'text-sm font-bold text-emerald-600';
        quickStatus.innerText = 'ชำระแล้ว (Paid)';
      } else if (inv.status === 'Pending Approval') {
        stBadge.className = 'inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200 animate-pulse';
        stBadge.innerHTML = '<i data-lucide="alert-circle" class="w-3.5 h-3.5 text-blue-600"></i> รอตรวจสอบสลิป';
        quickStatus.className = 'text-sm font-bold text-blue-600';
        quickStatus.innerText = 'รอตรวจสอบสลิป';
      } else {
        stBadge.className = 'inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200';
        stBadge.innerHTML = '<i data-lucide="hourglass" class="w-3.5 h-3.5 text-amber-500"></i> รอชำระเงิน (Unpaid)';
        quickStatus.className = 'text-sm font-bold text-amber-600';
        quickStatus.innerText = 'รอชำระเงิน';
      }

      // Quick summary cards
      document.getElementById('invQuickRoom').innerText = `ห้อง ${inv.roomNumber}`;
      document.getElementById('invQuickTenant').innerText = inv.fullName;
      document.getElementById('invQuickMonth').innerText = inv.billingMonth;
      document.getElementById('invQuickCreatedAt').innerText = inv.createdAt ? `ออกบิล: ${inv.createdAt}` : '';
      const totalNum = parseFloat(inv.totalAmount || 0);
      const totalFmt = totalNum.toLocaleString('th-TH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      document.getElementById('invQuickGrandTotal').innerText = `฿${totalFmt}`;
      document.getElementById('invRowGrandTotal').innerText = `${totalFmt} ฿`;

      // Tenant details
      document.getElementById('invTenantName').innerText = inv.fullName || '-';
      document.getElementById('invTenantPhone').innerText = inv.phone || 'ไม่ระบุเบอร์โทร';
      document.getElementById('invTenantEmail').innerText = inv.email || 'ไม่ระบุอีเมล';

      // Calculations
      const rent = parseFloat(inv.rentPrice || 0);
      document.getElementById('invRowRent').innerText = `${rent.toLocaleString('th-TH', {minimumFractionDigits: 2})} ฿`;

      const waterPrev = parseFloat(inv.waterPrev || 0);
      const waterCurr = parseFloat(inv.waterCurr || 0);
      const waterRate = parseFloat(inv.waterUnitRate || 18);
      const waterUnits = Math.max(0, waterCurr - waterPrev);
      const waterTotal = waterUnits * waterRate;
      document.getElementById('invWaterRateText').innerText = `หน่วยละ ${waterRate.toFixed(2)} บาท`;
      document.getElementById('invWaterCalcDetails').innerText = `มิเตอร์ ${waterPrev} ➔ ${waterCurr} (ใช้ไป ${waterUnits} หน่วย)`;
      document.getElementById('invRowWater').innerText = `${waterTotal.toLocaleString('th-TH', {minimumFractionDigits: 2})} ฿`;

      const elecPrev = parseFloat(inv.electricPrev || 0);
      const elecCurr = parseFloat(inv.electricCurr || 0);
      const elecRate = parseFloat(inv.electricUnitRate || 8);
      const elecUnits = Math.max(0, elecCurr - elecPrev);
      const elecTotal = elecUnits * elecRate;
      document.getElementById('invElectricRateText').innerText = `หน่วยละ ${elecRate.toFixed(2)} บาท`;
      document.getElementById('invElectricCalcDetails').innerText = `มิเตอร์ ${elecPrev} ➔ ${elecCurr} (ใช้ไป ${elecUnits} หน่วย)`;
      document.getElementById('invRowElectric').innerText = `${elecTotal.toLocaleString('th-TH', {minimumFractionDigits: 2})} ฿`;

      const otherFee = parseFloat(inv.otherFee || 0);
      document.getElementById('invRowOther').innerText = `${otherFee.toLocaleString('th-TH', {minimumFractionDigits: 2})} ฿`;
      const otherNoteEl = document.getElementById('invOtherFeeNoteText');
      if (otherNoteEl) {
        otherNoteEl.innerText = inv.otherFeeNote || (otherFee > 0 ? (Math.abs(otherFee - 20) < 0.01 ? 'ค่าขยะ (20.00 บาท)' : 'ค่าบริการส่วนกลาง') : '-');
      }

      // Slip image handling
      const slipImgBox = document.getElementById('invSlipWithImageBox');
      const noSlipBox = document.getElementById('invSlipNoImageBox');
      const slipImg = document.getElementById('invDetailSlipImage');
      const slipBadge = document.getElementById('invSlipBadge');
      const slipDlLink = document.getElementById('invSlipDownloadLink');

      currentModalSlipUrl = inv.slipImage || '';

      if (inv.slipImage && inv.slipImage.trim() !== '') {
        slipImgBox.classList.remove('hidden');
        noSlipBox.classList.add('hidden');
        slipImg.src = inv.slipImage;
        slipDlLink.href = inv.slipImage;
        slipBadge.innerText = 'มีแนบสลิป';
        slipBadge.className = 'text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700';
        quickSlip.innerText = 'มีสลิปแนบมา';
      } else {
        slipImgBox.classList.add('hidden');
        noSlipBox.classList.remove('hidden');
        slipImg.src = '';
        slipDlLink.href = '#';
        if (inv.slipRejectReason && inv.slipRejectReason.trim() !== '') {
          slipBadge.innerText = 'สลิปถูกส่งกลับ';
          slipBadge.className = 'text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-rose-100 text-rose-700';
          quickSlip.innerText = 'สลิปถูกส่งกลับ';
          noSlipBox.innerHTML = `
            <div class="w-14 h-14 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center mb-3">
              <i data-lucide="alert-triangle" class="w-7 h-7"></i>
            </div>
            <h5 class="text-sm font-bold text-rose-900 mb-1">สลิปเดิมถูกปฏิเสธแล้ว (รอแนบใหม่)</h5>
            <p class="text-xs text-rose-700 max-w-sm font-medium mb-3">เหตุผล: ${inv.slipRejectReason}</p>
            ${inv.oldSlipImage ? `
              <button type="button" onclick="window.open('${inv.oldSlipImage}', '_blank')" class="px-3.5 py-1.5 bg-white hover:bg-rose-50 text-rose-700 border border-rose-200 rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-2xs">
                <i data-lucide="image" class="w-3.5 h-3.5"></i> ดูสลิปเดิมที่ถูกปฏิเสธ
              </button>` : ''}
          `;
        } else {
          slipBadge.innerText = 'ไม่มีสลิป';
          slipBadge.className = 'text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-700';
          quickSlip.innerText = 'ไม่มีสลิปแนบ';
          noSlipBox.innerHTML = `
            <div class="w-14 h-14 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center mb-3">
              <i data-lucide="image-off" class="w-7 h-7"></i>
            </div>
            <h5 class="text-sm font-bold text-amber-900 mb-1">ยังไม่มีหลักฐานการโอนเงิน (ไม่มีสลิป)</h5>
            <p class="text-xs text-amber-700/80 max-w-xs">ผู้เช่ายังไม่ได้แนบสลิปผ่านระบบ หรือชำระด้วยเงินสดโดยตรงกับนิติบุคคล</p>
          `;
        }
      }

      // Print Receipt button link
      const printBtn = document.getElementById('invPrintReceiptBtn');
      if (printBtn) {
        printBtn.href = `print_receipt.php?id=${inv.invoiceId}`;
      }

      // Admin Action Button
      const actionArea = document.getElementById('invDetailActionArea');
      if (actionArea) {
        if (inv.status === 'Paid') {
          actionArea.innerHTML = `
            <form action="manage_invoice.php?tab=list" method="POST" class="m-0" onsubmit="return confirm('ต้องการยกเลิกสถานะบิล #INV-${inv.invoiceId} กลับเป็นยังไม่ชำระหรือไม่?');">
              <input type="hidden" name="invoiceId" value="${inv.invoiceId}">
              <button type="submit" name="update_payment_status" value="Unpaid" class="w-full py-3 px-4 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center justify-center gap-2 cursor-pointer">
                <i data-lucide="rotate-ccw" class="w-4 h-4"></i> ยกเลิกสถานะ (เปลี่ยนกลับเป็นรอชำระ)
              </button>
            </form>
          `;
        } else if (inv.slipImage && String(inv.slipImage).trim() !== '') {
          // ลูกบ้านแนบรูปสลิปแล้ว: ขึ้นทั้งปุ่มอนุมัติ และปุ่มปฏิเสธสลิป / ส่งกลับให้แนบใหม่
          actionArea.innerHTML = `
            <div class="flex flex-col sm:flex-row gap-2.5">
              <form action="manage_invoice.php?tab=list" method="POST" class="flex-1 m-0" onsubmit="return confirm('ยืนยันอนุมัติการชำระเงินสำหรับบิล #INV-${inv.invoiceId} หรือไม่?');">
                <input type="hidden" name="invoiceId" value="${inv.invoiceId}">
                <button type="submit" name="update_payment_status" value="Paid" class="w-full py-3 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-2xl text-xs sm:text-sm font-bold shadow-md shadow-emerald-600/20 transition flex items-center justify-center gap-2 cursor-pointer">
                  <i data-lucide="check-circle" class="w-4 h-4"></i> อนุมัติการชำระเงิน (ตั้งเป็น Paid)
                </button>
              </form>
              <button type="button" onclick="openRejectSlipModal(${inv.invoiceId})" class="w-full sm:w-auto py-3 px-4 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center justify-center gap-2 cursor-pointer shrink-0">
                <i data-lucide="x-circle" class="w-4 h-4 text-rose-600"></i> ปฏิเสธสลิป / ส่งกลับให้แนบใหม่
              </button>
            </div>
          `;
        } else if (inv.slipRejectReason && String(inv.slipRejectReason).trim() !== '') {
          // สลิปเดิมถูกปฏิเสธแล้ว
          actionArea.innerHTML = `
            <div class="w-full p-4 bg-rose-50 border border-rose-200 rounded-2xl text-xs text-rose-800 space-y-2">
              <div class="font-bold flex items-center gap-1.5 text-rose-900 text-sm">
                <i data-lucide="alert-triangle" class="w-4 h-4 text-rose-600"></i> สลิปเดิมถูกปฏิเสธแล้ว (ส่งกลับให้ลูกบ้านแนบใหม่)
              </div>
              <div class="bg-white/80 p-2.5 rounded-xl border border-rose-100 font-medium">
                <strong>เหตุผลที่ระบุ:</strong> ${inv.slipRejectReason}
              </div>
              <div class="text-slate-500 text-[11px] flex flex-wrap items-center justify-between gap-2 pt-1">
                <span>${inv.slipRejectedAt ? `ปฏิเสธเมื่อ: ${inv.slipRejectedAt}` : ''}</span>
                ${inv.oldSlipImage ? `
                  <button type="button" onclick="window.open('${inv.oldSlipImage}', '_blank')" class="text-xs text-rose-700 hover:text-rose-900 font-bold underline inline-flex items-center gap-1 cursor-pointer">
                    <i data-lucide="image" class="w-3.5 h-3.5"></i> ดูสลิปเดิมที่เคยปฏิเสธ
                  </button>` : ''}
              </div>
            </div>
          `;
        } else {
          // ยังไม่มีสลิป: ไม่ขึ้นปุ่มอนุมัติ
          actionArea.innerHTML = `
            <div class="w-full py-3 px-4 bg-slate-50 text-slate-500 rounded-2xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 border border-slate-200">
              <i data-lucide="clock" class="w-4 h-4 text-slate-400"></i> รอลูกบ้านแนบสลิปโอนเงิน (ปุ่มอนุมัติและปฏิเสธจะปรากฏเมื่อลูกบ้านแนบสลิป)
            </div>
          `;
        }
      }

      // Show modal
      const modal = document.getElementById('invoiceDetailModal');
      if (modal) modal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (window.lucide) {
        lucide.createIcons();
      }
    }

    // เปิดรูปสลิปเต็มจอ
    function openSlipFullWindow() {
      if (currentModalSlipUrl) {
        window.open(currentModalSlipUrl, '_blank');
      }
    }

    // ปิด Modal
    function closeInvoiceDetailModal() {
      const modal = document.getElementById('invoiceDetailModal');
      if (modal) modal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    // สลับโหมดเต็มจอ Fullscreen
    function toggleInvoiceFullscreen() {
      const dialog = document.getElementById('invoiceModalDialog');
      const icon = document.getElementById('invFsIcon');
      if (!dialog || !icon) return;

      isInvoiceFullscreen = !isInvoiceFullscreen;
      if (isInvoiceFullscreen) {
        dialog.classList.remove('sm:h-[97vh]', 'sm:max-w-[96vw]', '2xl:max-w-[94vw]', 'sm:rounded-3xl');
        dialog.classList.add('w-screen', 'h-screen', 'rounded-none');
        icon.setAttribute('data-lucide', 'minimize-2');
      } else {
        dialog.classList.remove('w-screen', 'h-screen', 'rounded-none');
        dialog.classList.add('sm:h-[97vh]', 'sm:max-w-[96vw]', '2xl:max-w-[94vw]', 'sm:rounded-3xl');
        icon.setAttribute('data-lucide', 'maximize-2');
      }
      if (window.lucide) lucide.createIcons();
    }

    // Backward compatibility for legacy openSlipModal calls
    function openSlipModal(imgUrl, invId, roomNo, tenant, total, month, status) {
      if (invId && invoiceMap[invId]) {
        openInvoiceDetailModal(invId);
      } else if (imgUrl) {
        window.open(imgUrl, '_blank');
      }
    }

    function closeSlipModal() {
      closeInvoiceDetailModal();
    }

    // --- ฟังก์ชันสำหรับ Modal ปฏิเสธสลิป / ส่งกลับให้แนบใหม่ ---
    let currentRejectSlipImgUrl = '';

    function openRejectSlipModal(invId) {
      const inv = invoiceMap[invId] || invoiceMap[String(invId)] || invoiceMap[Number(invId)];
      if (!inv) {
        console.error('Invoice not found:', invId);
        return;
      }

      document.getElementById('rejectSlipInvoiceId').value = inv.invoiceId;
      document.getElementById('rejectSlipModalSubtitle').innerText = `บิล #INV-${inv.invoiceId} • ห้อง ${inv.roomNumber} • ผู้เช่า ${inv.fullName}`;
      document.getElementById('rejectSlipPreviewTenant').innerText = `ผู้เช่า: ${inv.fullName} (ห้อง ${inv.roomNumber})`;
      const totalNum = parseFloat(inv.totalAmount || 0);
      document.getElementById('rejectSlipPreviewTotal').innerText = `ยอดบิล: ${totalNum.toLocaleString('th-TH', {minimumFractionDigits: 2})} บาท`;

      currentRejectSlipImgUrl = inv.slipImage || '';
      const imgEl = document.getElementById('rejectSlipPreviewImg');
      if (inv.slipImage && inv.slipImage.trim() !== '') {
        imgEl.src = inv.slipImage;
        imgEl.classList.remove('hidden');
      } else {
        imgEl.classList.add('hidden');
      }

      document.getElementById('rejectReasonInput').value = '';

      const modal = document.getElementById('rejectSlipModal');
      if (modal) {
        modal.style.zIndex = '99999';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
      }
      document.body.classList.add('overflow-hidden');

      setTimeout(() => {
        document.getElementById('rejectReasonInput')?.focus();
      }, 80);

      if (window.lucide) lucide.createIcons();
    }

    function closeRejectSlipModal() {
      const modal = document.getElementById('rejectSlipModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
      }
      const invModal = document.getElementById('invoiceDetailModal');
      if (!invModal || invModal.classList.contains('hidden')) {
        document.body.classList.remove('overflow-hidden');
      }
    }

    function setRejectReason(txt) {
      const input = document.getElementById('rejectReasonInput');
      if (input) {
        input.value = txt;
        input.focus();
      }
    }

    function openRejectSlipFullImage() {
      if (currentRejectSlipImgUrl) {
        window.open(currentRejectSlipImgUrl, '_blank');
      }
    }

    function validateRejectSlipForm() {
      const reason = document.getElementById('rejectReasonInput').value.trim();
      if (!reason) {
        alert('กรุณาระบุเหตุผลในการปฏิเสธสลิป');
        return false;
      }
      return confirm('ยืนยันปฏิเสธสลิปนี้และส่งกลับให้ผู้เช่าแนบใหม่ใช่หรือไม่?');
    }

    function handleStatusSubmit(e, form) {
      const btn = e.submitter;
      if (btn && btn.value === 'Paid') {
        return confirm('ยืนยันว่าได้รับเงินและตรวจสอบสลิปถูกต้องแล้วใช่หรือไม่?');
      } else {
        return confirm('ต้องการเปลี่ยนสถานะกลับเป็น "ยังไม่ชำระ" ใช่หรือไม่?');
      }
    }

    // ข้อมูลประวัติมิเตอร์ตามห้องที่ส่งมาจาก PHP
    window.roomMetersHistory = <?php echo json_encode($roomMetersHistory, JSON_UNESCAPED_UNICODE); ?>;

    const thaiMonthNames = [
      'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
      'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
    ];

    // แปลงชื่อรอบเดือน (ทั้งแบบ YYYY-MM และ ภาษาไทย เช่น "พฤศจิกายน 2026") เป็น {year, month, ymIndex}
    function parseBillingMonthToYm(str) {
      if (!str || typeof str !== 'string') return null;
      const yyyyMm = str.match(/^(\d{4})-(\d{2})$/);
      if (yyyyMm) {
        const y = parseInt(yyyyMm[1], 10);
        const m = parseInt(yyyyMm[2], 10);
        return { year: y, month: m, ymIndex: y * 12 + m };
      }
      const thaiMonthsMap = {
        'มกราคม': 1, 'กุมภาพันธ์': 2, 'มีนาคม': 3, 'เมษายน': 4,
        'พฤษภาคม': 5, 'มิถุนายน': 6, 'กรกฎาคม': 7, 'สิงหาคม': 8,
        'กันยายน': 9, 'ตุลาคม': 10, 'พฤศจิกายน': 11, 'ธันวาคม': 12
      };
      let month = null;
      for (const [name, num] of Object.entries(thaiMonthsMap)) {
        if (str.includes(name)) {
          month = num;
          break;
        }
      }
      let year = null;
      const yearMatch = str.match(/\b(20\d\d|25\d\d)\b/);
      if (yearMatch) {
        let y = parseInt(yearMatch[1], 10);
        if (y > 2400) y -= 543;
        year = y;
      }
      if (year && month) {
        return { year, month, ymIndex: year * 12 + month };
      }
      return null;
    }

    // คำนวณหาชื่อเดือนก่อนหน้า เช่น "2026-11" -> "ตุลาคม 2026"
    function getPrevMonthString(yyyyMm) {
      if (!yyyyMm || !yyyyMm.includes('-')) return '';
      const parts = yyyyMm.split('-');
      let year = parseInt(parts[0], 10);
      let month = parseInt(parts[1], 10);
      month -= 1;
      if (month < 1) {
        month = 12;
        year -= 1;
      }
      return `${thaiMonthNames[month - 1]} ${year}`;
    }

    // ฟังก์ชันค้นหามิเตอร์เก่าตามห้องและรอบเดือนที่เลือก (ดึงจากบิลเก่าก่อนหน้ารอบที่เลือก)
    function getPrevMeterForRoom(roomId, selectedMonth) {
      const history = window.roomMetersHistory ? window.roomMetersHistory[roomId] : null;
      const opt = document.querySelector(`#roomSelect option[value="${roomId}"]`);

      // ข้อมูลตั้งต้นจากสัญญาเช่า (วันทำสัญญา/วันย้ายเข้า)
      let leaseInitData = null;
      if (opt) {
        const initWater = opt.getAttribute('data-initial-water');
        const initElectric = opt.getAttribute('data-initial-electric');
        const leaseStart = opt.getAttribute('data-lease-start');
        if (initWater !== null && initWater !== '' && initElectric !== null && initElectric !== '') {
          leaseInitData = {
            water: initWater,
            electric: initElectric,
            month: leaseStart ? `วันทำสัญญา (${leaseStart})` : 'สัญญาเริ่มต้น (วันย้ายเข้า)',
            isInitial: true
          };
        }
      }

      // ถ้าไม่มีประวัติการออกบิลมาก่อนเลย
      if (!history || history.length === 0) {
        if (leaseInitData) return leaseInitData;
        if (opt && opt.getAttribute('data-water-prev') !== '' && opt.getAttribute('data-water-prev') !== null) {
          return {
            water: opt.getAttribute('data-water-prev'),
            electric: opt.getAttribute('data-electric-prev'),
            month: opt.getAttribute('data-last-month') || 'เดือนก่อน',
            isInitial: false
          };
        }
        return null;
      }

      // แปลงรอบเดือนเป้าหมายเป็น ymIndex
      const targetYm = parseBillingMonthToYm(selectedMonth);

      // ค้นหาบิลเก่าที่มีรอบก่อนหน้า selectedMonth
      let candidateBills = [];
      if (targetYm) {
        candidateBills = history.filter(item => {
          const itemYm = parseBillingMonthToYm(item.billingMonth);
          return itemYm && itemYm.ymIndex < targetYm.ymIndex;
        });
        // เรียงจากเดือนล่าสุดที่เก่ากว่าเป้าหมาย (descending)
        candidateBills.sort((a, b) => {
          const ymA = parseBillingMonthToYm(a.billingMonth);
          const ymB = parseBillingMonthToYm(b.billingMonth);
          if (ymB.ymIndex !== ymA.ymIndex) return ymB.ymIndex - ymA.ymIndex;
          return b.invoiceId - a.invoiceId;
        });
      }

      // 1. ถ้าพบบิลเก่าที่มีรอบก่อนหน้า ให้ใช้บิลล่าสุดก่อนหน้ารอบนั้น
      if (candidateBills.length > 0) {
        const best = candidateBills[0];
        return {
          water: best.waterCurr,
          electric: best.electricCurr,
          month: best.billingMonth || 'บิลก่อนหน้า',
          isInitial: false
        };
      }

      // 2. ถ้าไม่มีบิลเก่าก่อนหน้าเดือนที่เลือกเลย แต่มีมิเตอร์ตั้งต้นจากสัญญาเช่า
      if (leaseInitData) {
        return leaseInitData;
      }

      // 3. Fallback: ถ้าบิลในระบบเป็นเดือนอนาคตทั้งหมด พยายามหาบิลที่ไม่เกินเดือนปัจจุบัน
      const nowYm = parseBillingMonthToYm(new Date().toISOString().slice(0, 7));
      const notFutureBills = history.filter(item => {
        const itemYm = parseBillingMonthToYm(item.billingMonth);
        return itemYm && (!nowYm || itemYm.ymIndex <= nowYm.ymIndex);
      });
      if (notFutureBills.length > 0) {
        const best = notFutureBills[0];
        return {
          water: best.waterCurr,
          electric: best.electricCurr,
          month: best.billingMonth || 'เดือนก่อน',
          isInitial: false
        };
      }

      // 4. กรณีสุดท้าย ใช้บิลที่เก่าที่สุดในระบบ
      const oldestBill = history[history.length - 1];
      return {
        water: oldestBill.waterCurr,
        electric: oldestBill.electricCurr,
        month: oldestBill.billingMonth || 'บิลเก่า',
        isInitial: false
      };
    }

    // ฟังก์ชันดึงมิเตอร์เก่าใส่อัตโนมัติ
    function autoFillPreviousMeters(shouldFocusCurr = false) {
      const roomSelect = document.getElementById('roomSelect');
      const selectedOpt = roomSelect ? roomSelect.selectedOptions[0] : null;
      const roomId = selectedOpt ? selectedOpt.value : null;
      const monthInput = document.getElementById('billingMonthInput');
      const selectedMonth = monthInput ? monthInput.value : '';

      const waterPrevInput = document.getElementById('waterPrev');
      const electricPrevInput = document.getElementById('electricPrev');
      const waterBadge = document.getElementById('waterPrevSourceBadge');
      const electricBadge = document.getElementById('electricPrevSourceBadge');

      if (!roomId) {
        if (waterBadge) waterBadge.classList.add('hidden');
        if (electricBadge) electricBadge.classList.add('hidden');
        calculateLiveTotal();
        return;
      }

      const prevData = getPrevMeterForRoom(roomId, selectedMonth);

      if (prevData) {
        waterPrevInput.value = prevData.water;
        electricPrevInput.value = prevData.electric;
        
        const badgeText = prevData.isInitial 
          ? `✓ ดึงจาก${prevData.month}` 
          : `✓ ดึงจากรอบ ${prevData.month}`;
        const badgeColorClass = prevData.isInitial
          ? 'text-[10px] text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.2 rounded-full font-bold'
          : 'text-[10px] text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.2 rounded-full font-bold';

        if (waterBadge) {
          waterBadge.innerText = badgeText;
          waterBadge.className = badgeColorClass;
          waterBadge.classList.remove('hidden');
        }
        if (electricBadge) {
          electricBadge.innerText = badgeText;
          electricBadge.className = badgeColorClass;
          electricBadge.classList.remove('hidden');
        }
      } else {
        waterPrevInput.value = '0';
        electricPrevInput.value = '0';
        if (waterBadge) {
          waterBadge.innerText = 'รอบแรก (กรอกเลขเริ่มต้น)';
          waterBadge.className = 'text-[10px] text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.2 rounded-full font-bold';
          waterBadge.classList.remove('hidden');
        }
        if (electricBadge) {
          electricBadge.innerText = 'รอบแรก (กรอกเลขเริ่มต้น)';
          electricBadge.className = 'text-[10px] text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.2 rounded-full font-bold';
          electricBadge.classList.remove('hidden');
        }
      }

      calculateLiveTotal();

      if (shouldFocusCurr) {
        const waterCurrInput = document.getElementById('waterCurr');
        if (waterCurrInput) {
          setTimeout(() => waterCurrInput.focus(), 80);
        }
      }
    }

    function toggleEditPrevMeter(inputId) {
      const input = document.getElementById(inputId);
      if (input) {
        input.focus();
        input.select();
      }
    }

    // Auto-calculate live estimated total on form
        // Other Fee Dropdown & Checkbox functions
    function toggleOtherFeeDropdown(forceState = null) {
      const menu = document.getElementById('otherFeeMenu');
      const chevron = document.getElementById('otherFeeChevron');
      if (!menu) return;
      
      const shouldOpen = forceState !== null ? forceState : menu.classList.contains('hidden');
      if (shouldOpen) {
        recalculateOtherFees();
        menu.classList.remove('hidden');
        if (chevron) chevron.classList.add('rotate-180');
      } else {
        menu.classList.add('hidden');
        if (chevron) chevron.classList.remove('rotate-180');
      }
    }

    function recalculateOtherFees() {
      const roomSelect = document.getElementById('roomSelect');
      const selectedOpt = (roomSelect && roomSelect.value) ? (roomSelect.selectedOptions[0] || roomSelect.querySelector(`option[value="${roomSelect.value}"]`)) : null;

      // 1. ดึงข้อมูลสัญญาของห้องที่เลือก (ถ้ามี)
      const roomNumber = selectedOpt ? (selectedOpt.getAttribute('data-room') || '') : '';
      const tenantName = selectedOpt ? (selectedOpt.getAttribute('data-tenant') || '') : '';
      const parkingPlan = selectedOpt ? (selectedOpt.getAttribute('data-parking-plan') || 'None') : 'None';
      const parkingFee = selectedOpt ? parseFloat(selectedOpt.getAttribute('data-parking-fee') || 0) : 0;
      const internetPlan = selectedOpt ? (selectedOpt.getAttribute('data-internet-plan') || 'None') : 'None';
      const internetFee = selectedOpt ? parseFloat(selectedOpt.getAttribute('data-internet-fee') || 0) : 0;
      const vehicleType = selectedOpt ? (selectedOpt.getAttribute('data-vehicle-type') || 'Car') : 'Car';
      const parkingSource = selectedOpt ? (selectedOpt.getAttribute('data-parking-source') || 'lease') : 'lease';

      // อัปเดต Notice Bar แสดงว่ากำลังดึงข้อมูลห้องไหน
      const noticeBar = document.getElementById('otherFeeRoomNotice');
      if (noticeBar) {
        if (selectedOpt && roomNumber) {
          noticeBar.className = 'p-2.5 rounded-xl text-xs flex items-center justify-between border bg-blue-50/80 border-blue-200 text-blue-900 transition';
          noticeBar.innerHTML = `
            <span class="flex items-center gap-1.5 font-bold">
              <i data-lucide="home" class="w-3.5 h-3.5 text-blue-600 shrink-0"></i>
              <span>สัญญาห้อง ${roomNumber} (${tenantName})</span>
            </span>
            <span class="text-[10px] text-blue-700 bg-blue-100 font-bold px-2 py-0.5 rounded-md flex items-center gap-1">
              <i data-lucide="check" class="w-3 h-3 text-blue-600"></i> ดึงสัญญาเรียบร้อย
            </span>
          `;
        } else {
          noticeBar.className = 'p-2.5 rounded-xl text-xs flex items-center justify-between border bg-slate-50 border-slate-200 text-slate-600 transition';
          noticeBar.innerHTML = `
            <span class="flex items-center gap-1.5">
              <i data-lucide="info" class="w-3.5 h-3.5 text-blue-500 shrink-0"></i>
              <span>กรุณาเลือกห้องพักด้านบน ระบบจะดึง<b>ค่าที่จอดรถและค่าอินเทอร์เน็ตตามสัญญา</b>มาให้อัตโนมัติ</span>
            </span>
          `;
        }
      }

      // 2. รายการบริการล็อกประจำเดือน (ค่าขยะ 20 บาท + ค่าส่วนกลาง 100 บาท)
      const lockedFees = [
        { name: 'ค่าขยะ', amount: 20, badge: 'ล็อกจ่ายทุกเดือน', icon: 'trash-2' },
        { name: 'ค่าส่วนกลาง', amount: 100, badge: 'ล็อกจ่ายทุกเดือน', icon: 'layers' }
      ];

      // หากห้องพักมีสัญญาบริการอินเทอร์เน็ต (ล็อกตามสัญญา ทั้งรายเดือนและรายปี)
      const hasLeaseInternet = (selectedOpt && internetPlan && internetPlan !== 'None');
      if (hasLeaseInternet) {
        let netAmt = internetFee > 0 ? internetFee : 150;
        // ถ้ารายปีระบุ 1500 คิดเฉลี่ยรายเดือนตามรอบบิลเป็น 150 บาท
        if (internetPlan === 'Yearly' && netAmt >= 1000) {
          netAmt = 150;
        }
        if (netAmt <= 0) netAmt = 150;
        const netBadge = (internetPlan === 'Yearly') ? 'ล็อกตามสัญญา (รายปี)' : 'ล็อกตามสัญญา';
        lockedFees.push({
          name: 'ค่าอินเทอร์เน็ต / Wi-Fi',
          amount: netAmt,
          badge: netBadge,
          icon: 'wifi',
          noteName: (internetPlan === 'Yearly') ? 'ค่าอินเทอร์เน็ต / Wi-Fi (ตามสัญญารายปี)' : 'ค่าอินเทอร์เน็ต / Wi-Fi (ตามสัญญา)'
        });
      }

      // หากห้องพักมีสัญญาเช่าที่จอดรถ หรือมีสิทธิ์ที่จอดรถที่ได้รับอนุมัติแล้ว
      const hasLeaseParking = (selectedOpt && parkingPlan && parkingPlan !== 'None');
      if (hasLeaseParking) {
        let pLabel = 'ค่าที่จอดรถยนต์';
        let pIcon = 'car';
        if (vehicleType === 'Motorcycle') {
          pLabel = 'ค่าที่จอดมอเตอร์ไซค์';
          pIcon = 'bike';
        } else if (vehicleType === 'EV') {
          pLabel = 'ค่าที่จอดรถไฟฟ้า EV';
          pIcon = 'zap';
        } else if (vehicleType === 'Other') {
          pLabel = 'ค่าที่จอดรถ';
          pIcon = 'truck';
        }

        let pAmt = parkingFee > 0 ? parkingFee : 100;
        if (parkingPlan === 'Yearly' && pAmt >= 1000) {
          pAmt = 100;
        }
        if (pAmt <= 0) pAmt = 100;

        let pBadge = (parkingPlan === 'Yearly') ? 'ล็อกตามสัญญา (รายปี)' : 'ล็อกตามสัญญา';
        if (parkingSource === 'approved') {
          pBadge = 'ล็อกตามสิทธิ์อนุมัติ';
        }

        lockedFees.push({
          name: pLabel,
          amount: pAmt,
          badge: pBadge,
          icon: pIcon,
          noteName: (parkingPlan === 'Yearly') ? `${pLabel} (ตามสัญญารายปี)` : `${pLabel} (ตามสัญญา)`
        });
      }

      // 3. อัปเดตกล่องแสดงรายการล็อกใน Pop-up (lockedServicesContainer)
      const lockedContainer = document.getElementById('lockedServicesContainer');
      if (lockedContainer) {
        let lockedHtml = '';
        lockedFees.forEach(fee => {
          lockedHtml += `
            <div class="flex items-center justify-between p-2.5 bg-white rounded-xl border border-amber-200 shadow-2xs select-none">
              <div class="flex items-center gap-2.5">
                <div class="w-6 h-6 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
                  <i data-lucide="${fee.icon || 'lock'}" class="w-3.5 h-3.5"></i>
                </div>
                <div class="flex items-center gap-1.5 flex-wrap">
                  <span class="text-xs font-bold text-slate-800">${fee.name}</span>
                  <span class="inline-flex items-center gap-0.5 text-[9px] text-amber-800 bg-amber-100 font-bold px-1.5 py-0.5 rounded-md">${fee.badge}</span>
                </div>
              </div>
              <span class="text-xs font-mono font-bold text-amber-900 bg-amber-100/90 border border-amber-200 px-2.5 py-1 rounded-lg shadow-2xs">+${fee.amount.toFixed(0)} ฿</span>
            </div>
          `;
        });
        lockedContainer.innerHTML = lockedHtml;
      }

      // จัดการตัวเลือกเพิ่มเติมใน Optional Checkboxes: ซ่อนตัวเลือกที่ซ้ำกับสัญญา
      const optCarPark = document.getElementById('optParkingCarLabel');
      const optMotoPark = document.getElementById('optParkingMotoLabel');
      const optInternet = document.getElementById('optInternetLabel');
      const cbCarPark = document.getElementById('cbOptCarPark');
      const cbMotoPark = document.getElementById('cbOptMotoPark');
      const cbInternet = document.getElementById('cbOptInternet');

      if (hasLeaseParking) {
        if (optCarPark) optCarPark.style.display = 'none';
        if (optMotoPark) optMotoPark.style.display = 'none';
        if (cbCarPark) cbCarPark.checked = false;
        if (cbMotoPark) cbMotoPark.checked = false;
      } else {
        if (optCarPark) optCarPark.style.display = 'flex';
        if (optMotoPark) optMotoPark.style.display = 'flex';
      }

      if (hasLeaseInternet) {
        if (optInternet) optInternet.style.display = 'none';
        if (cbInternet) cbInternet.checked = false;
      } else {
        if (optInternet) optInternet.style.display = 'flex';
      }

      let sum = 0;
      let notes = [];
      const pillsContainer = document.getElementById('otherFeePills');
      if (pillsContainer) pillsContainer.innerHTML = '';

      // เพิ่มรายการล็อกบังคับเข้า sum และ pills เสมอ (ไม่มีช่องติ๊ก ล็อกตามราคาที่คิด)
      lockedFees.forEach(fee => {
        sum += fee.amount;
        notes.push(`${fee.noteName || fee.name} (฿${fee.amount.toFixed(2)})`);

        if (pillsContainer) {
          const pill = document.createElement('span');
          pill.className = 'inline-flex items-center gap-1.5 text-[10px] bg-amber-50 text-amber-900 border border-amber-200/90 px-2.5 py-0.5 rounded-full font-bold shadow-2xs select-none';
          pill.innerHTML = `<i data-lucide="${fee.icon || 'lock'}" class="w-3 h-3 text-amber-600 shrink-0"></i><span>${fee.name} ฿${fee.amount.toFixed(0)}</span><span class="text-[9px] text-amber-700 bg-amber-200/70 px-1.5 py-0.2 rounded-full font-semibold">${fee.badge.indexOf('สัญญา') !== -1 || fee.badge.indexOf('สิทธิ์') !== -1 ? 'ตามสัญญา' : 'ล็อก'}</span>`;
          pillsContainer.appendChild(pill);
        }
      });

      // 4. รายการเสริมอื่นๆ ที่ผู้ใช้ติ๊กเลือกเพิ่มเติม (ถ้ามีและยังแสดงอยู่)
      const optionalCheckboxes = document.querySelectorAll('.other-fee-checkbox:checked');
      optionalCheckboxes.forEach(cb => {
        // ข้าม checkbox ที่ถูกซ่อน
        if (cb.closest('label') && cb.closest('label').style.display === 'none') return;

        const amt = parseFloat(cb.getAttribute('data-amount') || 0);
        const name = cb.getAttribute('data-name') || 'ส่วนต่าง';
        sum += amt;
        notes.push(`${name} (฿${amt.toFixed(2)})`);

        // Add visual pill
        if (pillsContainer) {
          const pill = document.createElement('span');
          pill.className = 'inline-flex items-center gap-1.5 text-[10px] bg-blue-50 text-blue-700 border border-blue-200 px-2.5 py-0.5 rounded-full font-medium shadow-2xs';
          pill.innerHTML = `<span>${name} ฿${amt.toFixed(0)}</span><button type="button" onclick="removeFeePill('${name}')" class="text-blue-400 hover:text-rose-600 cursor-pointer ml-0.5 font-bold text-xs" title="ลบรายการนี้">&times;</button>`;
          pillsContainer.appendChild(pill);
        }
      });

      // 5. รายการกำหนดเอง (Custom Fee)
      const customName = document.getElementById('customFeeName')?.value.trim();
      const customAmt = parseFloat(document.getElementById('customFeeAmount')?.value || 0);
      if (customAmt > 0) {
        sum += customAmt;
        const displayName = customName ? customName : 'อื่นๆ';
        notes.push(`${displayName} (฿${customAmt.toFixed(2)})`);

        if (pillsContainer) {
          const pill = document.createElement('span');
          pill.className = 'inline-flex items-center gap-1.5 text-[10px] bg-slate-100 text-slate-700 border border-slate-200 px-2.5 py-0.5 rounded-full font-medium shadow-2xs';
          pill.innerHTML = `<span>${displayName} ฿${customAmt.toFixed(2)}</span><button type="button" onclick="clearCustomFee()" class="text-slate-400 hover:text-rose-600 cursor-pointer ml-0.5 font-bold text-xs" title="ลบรายการนี้">&times;</button>`;
          pillsContainer.appendChild(pill);
        }
      }

      // Update inputOtherFee value (ล็อกตามราคาที่คิดเลย)
      const otherInput = document.getElementById('inputOtherFee');
      if (otherInput) {
        otherInput.value = sum.toFixed(2);
      }

      // Update note hidden input
      const noteInput = document.getElementById('otherFeeNote');
      if (noteInput) {
        noteInput.value = notes.join(', ');
      }

      // Update popup total text
      const popupTotal = document.getElementById('otherFeePopupTotal');
      if (popupTotal) {
        popupTotal.innerText = `฿${sum.toFixed(2)}`;
      }

      // Update button label & count badge
      const btnDisplay = document.getElementById('otherFeeBtnDisplay');
      const countBadge = document.getElementById('otherFeeSelectedCount');
      const totalCount = lockedFees.length + optionalCheckboxes.length + (customAmt > 0 ? 1 : 0);

      if (btnDisplay) {
        if (optionalCheckboxes.length === 0 && customAmt <= 0) {
          let summaryLabel = `ค่าบริการประจำเดือน (฿${sum.toFixed(2)})`;
          if (hasLeaseInternet || hasLeaseParking) {
            summaryLabel = `ค่าบริการล็อกตามสัญญา (฿${sum.toFixed(2)})`;
          }
          btnDisplay.innerHTML = `<i data-lucide="lock" class="w-3.5 h-3.5 text-amber-600 shrink-0"></i><span class="truncate font-semibold text-slate-800">${summaryLabel} <span class="text-slate-400 font-normal">| คลิกดูรายละเอียด / เพิ่มเติม</span></span>`;
        } else {
          btnDisplay.innerHTML = `<i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-blue-600 shrink-0"></i><span class="truncate text-blue-700 font-bold">รวม ${totalCount} รายการ (฿${sum.toFixed(2)})</span>`;
        }
      }

      if (countBadge) {
        countBadge.innerText = `เลือก ${totalCount} รายการ`;
        countBadge.classList.remove('hidden');
      }

      if (window.lucide) {
        lucide.createIcons();
      }

      // Live calculate grand total
      calculateLiveTotal();
    }

    function removeFeePill(feeName) {
      const cbs = document.querySelectorAll('.other-fee-checkbox');
      cbs.forEach(cb => {
        if (cb.getAttribute('data-name') === feeName) {
          cb.checked = false;
        }
      });
      recalculateOtherFees();
    }

    function clearCustomFee() {
      const nameInput = document.getElementById('customFeeName');
      const amtInput = document.getElementById('customFeeAmount');
      if (nameInput) nameInput.value = '';
      if (amtInput) amtInput.value = '';
      recalculateOtherFees();
    }

    function clearOtherFees() {
      const cbs = document.querySelectorAll('.other-fee-checkbox');
      cbs.forEach(cb => {
        cb.checked = false;
      });
      clearCustomFee();
    }

    function handleManualOtherFeeInput() {
      // ล็อกไม่ให้แก้ไขได้ ล็อกตามราคาที่คิดเลย
      recalculateOtherFees();
    }

    function getMaxAllowedBillingMonth() {
      const now = new Date();
      const y = now.getFullYear();
      const m = now.getMonth() + 1; // รอบเดือนปัจจุบัน (ห้ามเลือกล่วงหน้า)
      return `${y}-${String(m).padStart(2, '0')}`;
    }

    function getNextBillingMonth(lastBillingMonthStr) {
      const maxAllowedStr = getMaxAllowedBillingMonth();
      const now = new Date();
      const currentYear = now.getFullYear();
      const currentMonth = now.getMonth() + 1;
      const currentMonthStr = `${currentYear}-${String(currentMonth).padStart(2, '0')}`;

      if (!lastBillingMonthStr || typeof lastBillingMonthStr !== 'string') {
        return currentMonthStr;
      }
      
      const ym = parseBillingMonthToYm(lastBillingMonthStr);
      if (ym) {
        let year = ym.year;
        let month = ym.month + 1;
        if (month > 12) {
          month = 1;
          year += 1;
        }
        const calculated = `${year}-${String(month).padStart(2, '0')}`;
        // ล็อกไม่ให้เกินรอบเดือนปัจจุบัน (ห้ามเลือกล่วงหน้า)
        if (calculated > maxAllowedStr) {
          return maxAllowedStr;
        }
        return calculated;
      }
      
      return currentMonthStr;
    }

    function setBillingMonthValue(targetValue) {
      const monthInput = document.getElementById('billingMonthInput');
      if (!monthInput || !targetValue) return;
      const maxAllowedStr = getMaxAllowedBillingMonth();
      if (targetValue > maxAllowedStr) {
        targetValue = maxAllowedStr;
      }
      monthInput.value = targetValue;
    }

    function handleRoomChange() {
      const roomSelect = document.getElementById('roomSelect');
      const selectedOpt = (roomSelect && roomSelect.value) ? (roomSelect.selectedOptions[0] || roomSelect.querySelector(`option[value="${roomSelect.value}"]`)) : null;
      if (selectedOpt && selectedOpt.value) {
        const lastMonth = selectedOpt.getAttribute('data-last-month');
        if (lastMonth && lastMonth.trim() !== '') {
          const nextMonth = getNextBillingMonth(lastMonth);
          setBillingMonthValue(nextMonth);
        }
      }
      recalculateOtherFees();
      autoFillPreviousMeters(true);
    }

    function handleBillingMonthChange() {
      const monthInput = document.getElementById('billingMonthInput');
      if (monthInput && monthInput.value) {
        const maxAllowedStr = getMaxAllowedBillingMonth();
        if (monthInput.value > maxAllowedStr) {
          setBillingMonthValue(maxAllowedStr);
          alert(`สามารถเลือกรอบบิลได้สูงสุดไม่เกินรอบเดือนปัจจุบัน (${maxAllowedStr}) หรือเลือกรอบเดือนย้อนหลังได้ครับ`);
        }
      }
      autoFillPreviousMeters(false);
    }

    function calculateLiveTotal() {
      const roomSelect = document.getElementById('roomSelect');
      const waterPrevInput = document.getElementById('waterPrev');
      if (!roomSelect || !waterPrevInput) return;

      const selectedOpt = roomSelect ? roomSelect.selectedOptions[0] : null;
      const rent = selectedOpt ? parseFloat(selectedOpt.getAttribute('data-rent') || 0) : 0;

      const waterPrev = parseFloat(document.getElementById('waterPrev')?.value || 0);
      const waterCurr = parseFloat(document.getElementById('waterCurr')?.value || 0);
      const waterRate = parseFloat(document.getElementById('waterRate')?.value || 18.00);
      const waterUnits = Math.max(0, waterCurr - waterPrev);
      const waterTotal = waterUnits * waterRate;

      const waterUnitsInput = document.getElementById('waterUnitsUsed');
      const waterCostPreview = document.getElementById('waterCostPreview');
      if (waterUnitsInput) {
        if (waterCurr > 0 && waterCurr < waterPrev) {
          waterUnitsInput.value = `⚠️ เลขใหม่น้อยกว่าเลขเก่า (${waterCurr} < ${waterPrev})`;
          waterUnitsInput.classList.add('border-rose-300', 'bg-rose-50', 'text-rose-700');
          waterUnitsInput.classList.remove('bg-sky-50/70', 'text-sky-950');
          if (waterCostPreview) waterCostPreview.innerText = `ตรวจสอบเลข`;
        } else {
          waterUnitsInput.value = `${waterUnits} หน่วย`;
          waterUnitsInput.classList.remove('border-rose-300', 'bg-rose-50', 'text-rose-700');
          waterUnitsInput.classList.add('bg-sky-50/70', 'text-sky-950');
          if (waterCostPreview) waterCostPreview.innerText = `฿${waterTotal.toFixed(2)}`;
        }
      }

      const electricPrev = parseFloat(document.getElementById('electricPrev')?.value || 0);
      const electricCurr = parseFloat(document.getElementById('electricCurr')?.value || 0);
      const electricRate = parseFloat(document.getElementById('electricRate')?.value || 8.00);
      const electricUnits = Math.max(0, electricCurr - electricPrev);
      const electricTotal = electricUnits * electricRate;

      const electricUnitsInput = document.getElementById('electricUnitsUsed');
      const electricCostPreview = document.getElementById('electricCostPreview');
      if (electricUnitsInput) {
        if (electricCurr > 0 && electricCurr < electricPrev) {
          electricUnitsInput.value = `⚠️ เลขใหม่น้อยกว่าเลขเก่า (${electricCurr} < ${electricPrev})`;
          electricUnitsInput.classList.add('border-rose-300', 'bg-rose-50', 'text-rose-700');
          electricUnitsInput.classList.remove('bg-amber-50/70', 'text-amber-950');
          if (electricCostPreview) electricCostPreview.innerText = `ตรวจสอบเลข`;
        } else {
          electricUnitsInput.value = `${electricUnits} หน่วย`;
          electricUnitsInput.classList.remove('border-rose-300', 'bg-rose-50', 'text-rose-700');
          electricUnitsInput.classList.add('bg-amber-50/70', 'text-amber-950');
          if (electricCostPreview) electricCostPreview.innerText = `฿${electricTotal.toFixed(2)}`;
        }
      }

      const other = parseFloat(document.getElementById('inputOtherFee').value || 0);
      const grandTotal = rent + waterTotal + electricTotal + other;

      const liveDisplay = document.getElementById('liveEstimatedTotal');
      if (liveDisplay) {
        liveDisplay.innerText = `฿${grandTotal.toLocaleString('th-TH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
      }
    }

    // ปิดการแจ้งเตือนอัตโนมัติ 4 วินาที และเรียก autoFill หากมีห้องถูกเลือกค้างไว้
    document.addEventListener("DOMContentLoaded", function() {
      // โหลดและคำนวณค่าบริการประจำเดือน (ค่าขยะ, ค่าส่วนกลาง ที่ล็อกไว้) อัตโนมัติ
      recalculateOtherFees();

      const roomSelect = document.getElementById('roomSelect');
      if (roomSelect && roomSelect.value) {
        autoFillPreviousMeters(false);
      }

      const alerts = document.querySelectorAll(".alert-box");
      alerts.forEach(function(alert) {
        setTimeout(function() {
          alert.style.transition = "opacity 0.4s ease-out, transform 0.4s ease-out";
          alert.style.opacity = "0";
          alert.style.transform = "translateY(-6px)";
          setTimeout(function() {
            alert.remove();
          }, 400);
        }, 4000); 
      });
    });

    // ปิด modal เมื่อกดปุ่ม ESC
    // ปิด dropdown ค่าส่วนต่างเมื่อคลิกด้านนอก
    document.addEventListener('click', function(e) {
      const group = document.getElementById('otherFeeDropdownGroup');
      const menu = document.getElementById('otherFeeMenu');
      if (group && menu && !group.contains(e.target)) {
        toggleOtherFeeDropdown(false);
      }
    });

    // ปิด modal เมื่อกดปุ่ม ESC
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        toggleOtherFeeDropdown(false);
        closeSlipModal();
        closeRejectRetroModal();
        closeRejectSlipModal();
      }
    });
  
    // Modal สำหรับยกเลิกคำขอออกบิลย้อนหลัง
    function openRejectRetroModal(reqId, roomNum, tenantName, month) {
      const modal = document.getElementById('rejectRetroModal');
      const idInput = document.getElementById('rejectRetroReqId');
      const sub = document.getElementById('rejectRetroSub');
      if (idInput) idInput.value = reqId;
      if (sub) sub.innerText = `ห้อง ${roomNum} (${tenantName}) • รอบเดือนที่ขอ: ${month}`;
      if (modal) modal.classList.remove('hidden');
    }

    function closeRejectRetroModal() {
      const modal = document.getElementById('rejectRetroModal');
      if (modal) modal.classList.add('hidden');
    }

    // คลิกที่แถวใบแจ้งหนี้เพื่อเปิดดูรายละเอียดแบบเต็มจอ
    document.addEventListener("DOMContentLoaded", function() {
      // หากมีการส่งค่า presetRoomId มาจากคำขอ ให้รัน autoFillPreviousMeters
      const roomSelect = document.getElementById('roomSelect');
      if (roomSelect && roomSelect.value) {
        autoFillPreviousMeters(false);
      }

      document.querySelectorAll('.invoice-row').forEach(row => {
        row.addEventListener('click', function(e) {
          if (e.target.closest('button') || e.target.closest('a') || e.target.closest('form') || e.target.closest('input')) {
            return;
          }
          const invId = this.getAttribute('data-invoice-id');
          if (invId) {
            openInvoiceDetailModal(invId);
          }
        });
      });
    });

    function openSlipPreviewModal(src) {
        var img = document.getElementById('retroSlipPreviewImage');
        var modal = document.getElementById('retroSlipPreviewModal');
        if (img && modal) {
            img.src = src;
            modal.classList.remove('hidden');
        }
        if (window.lucide) lucide.createIcons();
    }
    function closeSlipPreviewModal() {
        var modal = document.getElementById('retroSlipPreviewModal');
        if (modal) modal.classList.add('hidden');
        var img = document.getElementById('retroSlipPreviewImage');
        if (img) img.src = '';
    }
</script>

<!-- Modal สำหรับยกเลิกคำขอรอบบิลย้อนหลัง -->
<div id="rejectRetroModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4" onclick="closeRejectRetroModal()">
  <div class="modal-enter w-full max-w-md bg-white rounded-3xl shadow-2xl border border-slate-100 overflow-hidden" onclick="event.stopPropagation()">
    <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-rose-50/50">
      <div class="flex items-center gap-2.5">
        <div class="w-9 h-9 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shadow-2xs">
          <i data-lucide="x-circle" class="w-5 h-5"></i>
        </div>
        <div>
          <h4 class="font-display font-bold text-slate-800 text-base">ยกเลิกคำขอรอบบิลย้อนหลัง</h4>
          <p class="text-xs text-slate-400" id="rejectRetroSub">ห้อง --</p>
        </div>
      </div>
      <button type="button" onclick="closeRejectRetroModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg transition">
        <i data-lucide="x" class="w-4 h-4"></i>
      </button>
    </div>
    <form action="manage_invoice.php?tab=retro" method="POST" class="p-6 space-y-4">
      <input type="hidden" name="requestId" id="rejectRetroReqId" value="">
      <div>
        <label class="block text-xs font-bold text-slate-700 mb-1.5">เหตุผลหรือหมายเหตุในการยกเลิกคำขอ (ส่งให้ผู้เช่าทราบ):</label>
        <textarea name="adminRemark" rows="3" placeholder="เช่น สัญญาเช่าสิ้นสุดแล้ว หรือ มีการออกบิลให้ช่องทางอื่นแล้ว..." class="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-400 focus:bg-white outline-none font-sans"></textarea>
      </div>
      <div class="flex items-center justify-end gap-2 pt-2">
        <button type="button" onclick="closeRejectRetroModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-bold transition cursor-pointer">ยกเลิก</button>
        <button type="submit" name="reject_retro_request" class="px-5 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold shadow-sm transition cursor-pointer">ยืนยันยกเลิกคำขอ</button>
      </div>
    </form>
  </div>
</div>


  <!-- 🌟 MODAL: ดูรูปภาพสลิปที่แนบมากับคำขอบิลย้อนหลัง 🌟 -->
  <div id="retroSlipPreviewModal" class="hidden fixed inset-0 z-[99999] bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4" onclick="closeSlipPreviewModal()">
      <div class="relative max-w-lg w-full flex flex-col items-center justify-center" onclick="event.stopPropagation()">
          <button type="button" onclick="closeSlipPreviewModal()" class="absolute -top-12 right-0 text-white hover:text-rose-400 p-2 text-xs font-bold flex items-center gap-1 bg-white/10 hover:bg-white/20 rounded-full px-3.5 py-1.5 transition cursor-pointer">
              <i data-lucide="x" class="w-4 h-4"></i> ปิดหน้าต่าง
          </button>
          <img id="retroSlipPreviewImage" src="" alt="ภาพสลิปที่แนบมา" class="max-w-full max-h-[85vh] rounded-2xl shadow-2xl object-contain border border-white/20 bg-black">
      </div>
  </div>

</body>
</html>