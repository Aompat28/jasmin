<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบสิทธิ์ ต้องเป็น Tenant เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Tenant') {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$tenantName = $_SESSION['full_name'] ?? 'ผู้เช่า';
$initials = strtoupper(mb_substr($tenantName, 0, 2, 'UTF-8'));
$stmtHdrR = $conn->prepare("SELECT r.roomNumber FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC LIMIT 1");
$stmtHdrR->execute([$userId]);
$myRoomNumber = $stmtHdrR->fetchColumn() ?: '';
$message = "";

// 1. เมื่อลูกบ้านกดอัปโหลดสลิปชำระเงิน
if (isset($_POST['upload_slip'])) {
    $invoiceId = $_POST['invoiceId'];
    
    if (isset($_FILES['slip_image']) && $_FILES['slip_image']['error'] == 0) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
        
        $fileName = time() . '_slip_' . basename($_FILES['slip_image']['name']);
        $targetFilePath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['slip_image']['tmp_name'], $targetFilePath)) {
            $stmtUp = $conn->prepare("UPDATE Invoices SET slipImage = ?, status = 'Pending Approval' WHERE invoiceId = ? AND tenantId = ?");
            $stmtUp->execute([$targetFilePath, $invoiceId, $userId]);
            $message = "<div style='color: green; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px;'>✅ อัปโหลดสลิปสำเร็จ! กรุณารอผู้ดูแลระบบตรวจสอบความถูกต้อง</div>";
        } else {
            $message = "<div style='color: red; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px;'>❌ ไม่สามารถอัปโหลดไฟล์ได้ กรุณาลองใหม่อีกครั้ง</div>";
        }
    } else {
        $message = "<div style='color: red; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px;'>❌ กรุณาเลือกไฟล์รูปภาพสลิปโอนเงิน</div>";
    }
}

// 2. ดึงข้อมูลใบแจ้งหนี้ทั้งหมดของลูกบ้านคนนี้
$stmtInv = $conn->prepare("
    SELECT i.*, r.roomNumber 
    FROM Invoices i
    JOIN Room r ON i.roomId = r.roomId
    WHERE i.tenantId = ?
    ORDER BY i.invoiceId DESC
");
$stmtInv->execute([$userId]);
$myInvoices = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

// 🌟 ระบบค้นหารูป QR Code อัตโนมัติด้วย Base64
$qrImageSrc = "";
$imgDirPath = __DIR__ . DIRECTORY_SEPARATOR . 'images';
$debugFiles = "";

if (is_dir($imgDirPath)) {
    $filesInDir = array_diff(scandir($imgDirPath), array('.', '..'));
    $debugFiles = implode(", ", $filesInDir); 
    
    foreach ($filesInDir as $file) {
        if (stripos($file, 'qrcode') !== false) {
            $qrFilePath = $imgDirPath . DIRECTORY_SEPARATOR . $file;
            $qrImageData = file_get_contents($qrFilePath);
            $ext = strtolower(pathinfo($qrFilePath, PATHINFO_EXTENSION));
            $mime = 'image/' . ($ext === 'jpg' || $ext === 'jpeg' ? 'jpeg' : 'png');
            $qrImageSrc = 'data:' . $mime . ';base64,' . base64_encode($qrImageData);
            break; 
        }
    }
} else {
    $debugFiles = "ไม่พบโฟลเดอร์ images ในระบบ";
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>jasmin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        body { font-family: 'Prompt', sans-serif; background-color: #FAF4F6; padding: 20px; color: #1e293b; }
        .container { max-width: 950px; margin: 0 auto; }
        .back-btn-pill:hover { background-color: #FFF0F3 !important; color: #0f172a !important; transform: translateX(-2px); }
        .invoice-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1.25rem; padding: 25px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .invoice-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px; }
        .details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .detail-box { background: #FAF4F6; padding: 15px; border-radius: 1rem; border: 1px solid #f1f5f9; font-size: 13px; }
        .detail-title { font-weight: bold; color: #64748b; margin-bottom: 6px; }
        .detail-val { font-size: 16px; font-weight: bold; color: #1e293b; font-family: 'Plus Jakarta Sans', monospace; }
        .payment-section { background: #fdf4ff; border: 1px solid #f5d0fe; padding: 20px; border-radius: 1rem; margin-top: 20px; display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
        
        /* สไตล์สำหรับรูป QR Code */
        .qr-box { background: white; padding: 10px; border-radius: 1rem; border: 1px solid #d8b4fe; text-align: center; width: 140px; flex-shrink: 0; }
        .qr-box img { width: 120px; height: 120px; object-fit: contain; cursor: zoom-in; transition: transform 0.2s ease; border-radius: 0.5rem; }
        .qr-box img:hover { transform: scale(1.05); }
        
        /* สไตล์สำหรับกล่องขยายรูปภาพ (Modal) */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { max-width: 90%; max-height: 90%; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        .close-modal { position: absolute; top: 20px; right: 30px; color: white; font-size: 40px; font-weight: bold; cursor: pointer; text-shadow: 0 2px 4px rgba(0,0,0,0.5); }
        .close-modal:hover { color: #f87171; }

        /* สไตล์สำหรับปุ่มคัดลอก */
        .copy-btn { background: #FFF0F3; color: #334155; border: 1px solid #cbd5e1; padding: 4px 10px; font-size: 12px; font-weight: bold; border-radius: 8px; cursor: pointer; margin-left: 8px; transition: 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .copy-btn:hover { background: #e2e8f0; }

        .total-row { display: flex; justify-content: space-between; align-items: center; background: #eff6ff; padding: 15px 20px; border-radius: 1rem; border: 1px solid #bfdbfe; margin-top: 15px; }
        .total-amount { font-size: 24px; font-weight: bold; color: #2563eb; font-family: 'Plus Jakarta Sans', monospace; }
        .badge { padding: 6px 14px; border-radius: 9999px; font-size: 12px; font-weight: bold; color: white; }
        .upload-box { margin-top: 15px; padding-top: 15px; border-top: 1px dashed #cbd5e1; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; width: 100%; }
        button.btn-upload { background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 12px; font-weight: bold; cursor: pointer; font-size: 14px; transition: all 0.2s; }
        button.btn-upload:hover { background: #059669; }
        .img-link { color: #4f46e5; text-decoration: none; font-weight: bold; font-size: 13px; }
        .img-link:hover { text-decoration: underline; }
    </style>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body>

<!-- 🌟 กล่อง Modal สำหรับซูมรูป QR Code -->
<div id="qrModal" class="modal" onclick="closeModal()">
    <span class="close-modal">&times;</span>
    <img id="qrModalImg" class="modal-content">
</div>

<div class="container">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <a href="tenant_dashboard.php" class="back-btn-pill" style="display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; background: white; border: 1px solid #cbd5e1; border-radius: 14px; color: #475569; text-decoration: none; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.06); shrink: 0;" title="ย้อนกลับหน้าหลัก">
                <i data-lucide="arrow-left" style="width: 22px; height: 22px;"></i>
            </a>
            <div>
                <h2 style="margin: 0; font-size: 24px; color: #1e293b;">💰 ตรวจสอบบิลและช่องทางชำระเงิน</h2>
                <p style="margin: 3px 0 0 0; font-size: 13px; color: #64748b;">รายการใบแจ้งหนี้ประจำเดือน และหลักฐานการชำระเงิน</p>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="profile.php" style="display: flex; align-items: center; gap: 10px; text-decoration: none; padding: 6px 12px; background: white; border: 1px solid #cbd5e1; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                <div style="width: 32px; height: 32px; border-radius: 50%; background: #dbeafe; color: #1d4ed8; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px;">
                    <?php echo $initials; ?>
                </div>
                <div style="text-align: left;">
                    <div style="font-size: 12px; font-weight: bold; color: #1e293b;"><?php echo htmlspecialchars($tenantName); ?></div>
                    <?php if(!empty($myRoomNumber)): ?>
                        <div style="font-size: 11px; color: #2563eb; font-weight: bold;">● ห้อง <?php echo htmlspecialchars($myRoomNumber); ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <a href="logout.php" onclick="return confirm('ออกจากระบบ?');" style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: #fff1f2; color: #e11d48; text-decoration: none; border: 1px solid #fecdd3;" title="ออกจากระบบ">
                <i data-lucide="log-out" style="width: 18px; height: 18px;"></i>
            </a>
        </div>
    </div>
    
    <?php echo $message; ?>

    <?php if(count($myInvoices) > 0): ?>
        <?php foreach($myInvoices as $inv): ?>
        <div class="invoice-card">
            <div class="invoice-header">
                <div>
                    <h3 style="margin: 0; color: #1e3a8a;">ใบแจ้งหนี้รอบเดือน: <?php echo htmlspecialchars($inv['billingMonth']); ?></h3>
                    <small style="color: #64748b;">รหัสบิล: #INV-<?php echo $inv['invoiceId']; ?> | ห้อง <?php echo $inv['roomNumber']; ?></small>
                </div>
                <div>
                    <?php if($inv['status'] === 'Unpaid'): ?>
                        <span class="badge" style="background-color: #f59e0b;">รอชำระเงิน</span>
                    <?php elseif($inv['status'] === 'Pending Approval'): ?>
                        <span class="badge" style="background-color: #3b82f6;">รอตรวจสอบสลิป</span>
                    <?php elseif($inv['status'] === 'Paid'): ?>
                        <span class="badge" style="background-color: #10b981;">ชำระเงินแล้ว (Paid)</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="details-grid">
                <div class="detail-box">
                    <div class="detail-title">🏠 ค่าเช่าห้องพัก</div>
                    <div class="detail-val"><?php echo number_format($inv['rentPrice'], 2); ?> ฿</div>
                </div>
                <div class="detail-box">
                    <div class="detail-title">💧 ค่าน้ำ (ยูนิตละ <?php echo $inv['waterUnitRate']; ?> ฿)</div>
                    <div class="detail-val"><?php echo number_format(($inv['waterCurr'] - $inv['waterPrev']) * $inv['waterUnitRate'], 2); ?> ฿</div>
                    <small style="color: #64748b;">(มิเตอร์: <?php echo $inv['waterPrev']; ?> ➡ <?php echo $inv['waterCurr']; ?>)</small>
                </div>
                <div class="detail-box">
                    <div class="detail-title">⚡ ค่าไฟ (ยูนิตละ <?php echo $inv['electricUnitRate']; ?> ฿)</div>
                    <div class="detail-val"><?php echo number_format(($inv['electricCurr'] - $inv['electricPrev']) * $inv['electricUnitRate'], 2); ?> ฿</div>
                    <small style="color: #64748b;">(มิเตอร์: <?php echo $inv['electricPrev']; ?> ➡ <?php echo $inv['electricCurr']; ?>)</small>
                </div>
                <?php if($inv['otherFee'] > 0): ?>
                <div class="detail-box">
                    <div class="detail-title">📌 ค่าใช้จ่ายอื่นๆ</div>
                    <div class="detail-val"><?php echo number_format($inv['otherFee'], 2); ?> ฿</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="total-row">
                <div><span style="font-size: 15px; color: #475569; font-weight: bold;">ยอดชำระสุทธิทั้งสิ้น:</span></div>
                <div class="total-amount"><?php echo number_format($inv['totalAmount'], 2); ?> บาท</div>
            </div>

            <!-- 🌟 ส่วนแสดง QR Code และปุ่มคัดลอก -->
            <?php if($inv['status'] === 'Unpaid'): ?>
            <div class="payment-section">
                <div class="qr-box">
                    <?php if(!empty($qrImageSrc)): ?>
                        <!-- เพิ่มคำสั่ง onclick="openModal(...)" เพื่อขยายรูป -->
                        <img src="<?php echo $qrImageSrc; ?>" alt="QR Code พร้อมเพย์หอพัก" onclick="openModal('<?php echo $qrImageSrc; ?>')" title="คลิกเพื่อขยายรูปภาพ">
                    <?php else: ?>
                        <div style="font-size: 11px; color: #dc2626; text-align: left; line-height: 1.4; word-break: break-all;">
                            <b>หารูปไม่เจอ!</b><br>ไฟล์ที่เห็น: <span style="color: #475569;"><?php echo empty($debugFiles) ? "ไม่มีไฟล์" : htmlspecialchars($debugFiles); ?></span>
                        </div>
                    <?php endif; ?>
                    <div style="font-size: 11px; font-weight: bold; color: #6b21a8; margin-top: 5px;">🔍 คลิกเพื่อขยาย</div>
                </div>
                <div style="flex: 1;">
                    <h4 style="margin: 0 0 5px 0; color: #6b21a8;">📱 ช่องทางการชำระเงิน</h4>
                    <p style="margin: 0 0 8px 0; font-size: 13px; color: #475569;">
                        ท่านสามารถสแกนคิวอาร์โค้ด หรือโอนเข้าบัญชีหอพัก จากนั้นแนบสลิปหลักฐานการโอนด้านล่างนี้เพื่อให้แอดมินตรวจสอบครับ
                    </p>
                    <div style="font-size: 13px; color: #334155; font-weight: bold; background: white; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1; display: inline-block;">
                        🏦 ธนาคารกสิกรไทย (KBANK) <br>
                        ชื่อบัญชี: หอพักจัสมิน <br>
                        เลขบัญชี: 109-3-21573-3 
                        <!-- 🌟 ปุ่มคัดลอกเลขบัญชี (เลขที่จะก๊อปคือ 1093215733 เพื่อให้ง่ายต่อการวางในแอป) -->
                        <button type="button" class="copy-btn" onclick="copyToClipboard('1093215733', this)">
                            📋 คัดลอกเลขบัญชี
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="upload-box">
                <?php if($inv['status'] === 'Unpaid'): ?>
                    <form action="tenant_invoice.php" method="POST" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 12px; width: 100%; flex-wrap: wrap;">
                        <input type="hidden" name="invoiceId" value="<?php echo $inv['invoiceId']; ?>">
                        <label style="font-size: 14px; font-weight: bold; color: #b45309; margin:0;">📤 แนบสลิปโอนเงิน:</label>
                        <input type="file" name="slip_image" accept="image/*" required style="font-size: 13px; padding: 6px; background: white; border: 1px solid #cbd5e1; border-radius: 6px; flex: 1; min-width: 200px;">
                        <button type="submit" name="upload_slip" class="btn-upload">ส่งสลิปชำระเงิน</button>
                    </form>
                <?php elseif($inv['status'] === 'Pending Approval'): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">
                        <span style="color: #2563eb; font-size: 14px; font-weight: bold;">⏳ ส่งหลักฐานสลิปแล้ว กำลังรอผู้ดูแลระบบตรวจสอบอนุมัติ</span>
                        <?php if(!empty($inv['slipImage'])): ?>
                            <a href="<?php echo htmlspecialchars($inv['slipImage']); ?>" target="_blank" class="img-link">🖼️ ดูสลิปที่แนบไว้</a>
                        <?php endif; ?>
                    </div>
                <?php elseif($inv['status'] === 'Paid'): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">
                        <span style="color: #059669; font-size: 14px; font-weight: bold;">✔ ชำระเงินเรียบร้อยแล้ว</span>
                        <?php if(!empty($inv['slipImage'])): ?>
                            <a href="<?php echo htmlspecialchars($inv['slipImage']); ?>" target="_blank" class="img-link">🖼️ ดูสลิปการโอน</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align: center; color: #64748b; padding: 40px; background: #ffffff; border-radius: 12px; border: 1px solid #cbd5e1;">
            ยังไม่มีใบแจ้งหนี้หรือบิลในรอบเดือนนี้
        </div>
    <?php endif; ?>

</div>

<!-- 🌟 Script สำหรับทำงานระบบคลิกขยายรูปภาพ และคัดลอกข้อความ -->
<script>
    // 1. ฟังก์ชันขยายรูป QR Code
    function openModal(imageSrc) {
        document.getElementById('qrModal').style.display = 'flex';
        document.getElementById('qrModalImg').src = imageSrc;
    }

    // ฟังก์ชันปิดรูป QR Code
    function closeModal() {
        document.getElementById('qrModal').style.display = 'none';
    }

    // 2. ฟังก์ชันคัดลอกเลขบัญชี
    function copyToClipboard(text, btnElement) {
        navigator.clipboard.writeText(text).then(() => {
            // สำรองข้อความเดิมของปุ่มไว้
            let originalHtml = btnElement.innerHTML;
            let originalBg = btnElement.style.background;
            
            // เปลี่ยนข้อความและสีปุ่มเพื่อแจ้งว่าก๊อปปี้สำเร็จแล้ว
            btnElement.innerHTML = "✅ คัดลอกสำเร็จ!";
            btnElement.style.background = "#d4edda";
            btnElement.style.color = "#155724";
            btnElement.style.borderColor = "#c3e6cb";

            // คืนค่าปุ่มกลับเหมือนเดิมหลังจาก 2 วินาที
            setTimeout(() => {
                btnElement.innerHTML = originalHtml;
                btnElement.style.background = originalBg;
                btnElement.style.color = "#334155";
                btnElement.style.borderColor = "#cbd5e1";
            }, 2000);
        }).catch(err => {
            alert('เบราว์เซอร์ของคุณไม่รองรับการคัดลอกอัตโนมัติ กรุณาเลือกคลุมและกดคัดลอกด้วยตนเองครับ');
        });
    }
    if (window.lucide) { lucide.createIcons(); }
</script>

</body>
</html>