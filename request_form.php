<?php
session_start();
require_once 'includes/db_connect.php';

// ต้องเป็นผู้เช่า (Tenant) เท่านั้นถึงจะเข้าหน้านี้ได้
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Tenant') {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$message = "";

if (isset($_POST['submit_request'])) {
    $category = $_POST['category'];
    $description = $_POST['description'];
    
    // รับค่าวัน-เวลาที่สะดวก และการอนุญาตเข้าห้อง
    $preferredDate = !empty($_POST['preferredDate']) ? $_POST['preferredDate'] : null;
    $preferredTime = !empty($_POST['preferredTime']) ? $_POST['preferredTime'] : null;
    
    $todayStr = date('Y-m-d');
    $currentTimeStr = date('H:i');
    $isPastTime = false;

    if (empty($preferredDate)) {
        $message = "<div style='color: red; padding: 10px; background: #f8d7da; margin-bottom: 15px; border-radius: 5px;'>❌ กรุณาระบุวันที่สะดวกเข้าห้อง</div>";
    } elseif ($preferredDate < $todayStr) {
        $isPastTime = true;
    } elseif ($preferredDate === $todayStr && $preferredTime && $preferredTime < $currentTimeStr) {
        $isPastTime = true;
    }

    if ($isPastTime) {
        $message = "<div style='color: red; padding: 10px; background: #f8d7da; margin-bottom: 15px; border-radius: 5px;'>❌ ไม่สามารถเลือกเวลาที่ผ่านมาแล้วได้ กรุณาเลือกเวลาปัจจุบันหรือหลังจากนี้เท่านั้น</div>";
    } elseif (!isset($_POST['allowEntry']) || $_POST['allowEntry'] != '1') {
        $message = "<div style='color: red; padding: 10px; background: #f8d7da; margin-bottom: 15px; border-radius: 5px;'>❌ กรุณากดยินยอมให้นิติบุคคลใช้กุญแจสำรองเข้าห้องได้ก่อนส่งคำร้อง</div>";
    } else {
        $allowEntry = 1;
    
    // ระบบอัปโหลดรูปภาพความเสียหาย
    $requestImage = null;
    if (isset($_FILES['request_image']) && $_FILES['request_image']['error'] == 0) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
        $fileName = time() . '_req_' . basename($_FILES['request_image']['name']);
        $targetFilePath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['request_image']['tmp_name'], $targetFilePath)) {
            $requestImage = $targetFilePath;
        }
    }
    
    // หา roomId ปัจจุบันของลูกบ้าน
    $stmtRoom = $conn->prepare("SELECT roomId FROM Lease WHERE tenantId = ? AND status = 'Active' LIMIT 1");
    $stmtRoom->execute([$userId]);
    $lease = $stmtRoom->fetch(PDO::FETCH_ASSOC);
    
    if ($lease) {
        // เพิ่ม preferredDate, preferredTime, allowEntry, requestDate ลงในคำสั่ง SQL
        $stmt = $conn->prepare("INSERT INTO ServiceRequest (tenantId, roomId, category, description, preferredDate, preferredTime, allowEntry, status, requestImage, requestDate) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', ?, NOW())");
        $stmt->execute([$userId, $lease['roomId'], $category, $description, $preferredDate, $preferredTime, $allowEntry, $requestImage]);
        
        $message = "
        <div style='color: #065f46; padding: 20px; background: #d1fae5; margin-bottom: 20px; border-radius: 8px; text-align: center; border: 1px solid #34d399;'>
            <h3 style='margin-top:0;'>✅ ส่งเรื่องแจ้งซ่อมเรียบร้อยแล้ว!</h3>
            <p>ช่างและผู้ดูแลระบบได้รับข้อมูลของคุณแล้ว</p>
            <a href='service_request.php' style='display: inline-block; background: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: bold; margin-top: 10px;'>ไปหน้าประวัติการแจ้งซ่อม ➡</a>
        </div>";
    } else {
        $message = "<div style='color: red; padding: 10px; background: #f8d7da; margin-bottom: 15px; border-radius: 5px;'>❌ ไม่พบข้อมูลสัญญาเช่าที่เปิดใช้งานอยู่</div>";
    }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>jasmin</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: #f8fafc; padding: 30px; color: #1e293b; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .back-btn { text-decoration: none; color: #4f46e5; font-weight: bold; display: inline-block; margin-bottom: 20px; }
        .form-box { background: #f8fafc; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; }
        label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 14px; }
        input[type="text"], input[type="date"], select, textarea, input[type="file"] { width: 100%; padding: 10px; margin-top: 5px; margin-bottom: 20px; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
        button { background: #4f46e5; color: white; border: none; padding: 12px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; width: 100%; font-size: 16px; margin-top: 10px; }
        button:hover { background: #4338ca; }
        
        /* สไตล์สำหรับกล่องนัดหมาย */
        .appointment-box { background: #ffffff; padding: 15px; border-radius: 8px; border: 1px solid #cbd5e1; margin-bottom: 20px; }
        .flex-row { display: flex; gap: 15px; }
        .flex-col { flex: 1; }
        .checkbox-container { display: flex; align-items: center; gap: 10px; margin-top: 5px; background: #fffbeb; padding: 10px; border-radius: 6px; border: 1px solid #fde68a; cursor: pointer; }
        .checkbox-container input { width: auto; margin: 0; transform: scale(1.2); cursor: pointer; }
        .checkbox-container span { font-size: 14px; color: #b45309; font-weight: bold; }
    </style>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body>
<div class="container">
    <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 20px;">
        <a href="tenant_dashboard.php" style="display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; background: white; border: 1px solid #cbd5e1; border-radius: 12px; color: #475569; text-decoration: none; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.06);" title="ย้อนกลับหน้าหลัก">
            <span style="font-size: 20px; font-weight: bold; line-height: 1;">←</span>
        </a>
        <div>
            <h2 style="margin: 0; font-size: 22px;">🔧 แจ้งซ่อมและขอบริการใหม่</h2>
            <span style="font-size: 13px; color: #64748b;">ส่งเรื่องให้นิติบุคคลเพื่อตรวจสอบและจัดสรรช่าง</span>
        </div>
    </div>
    
    <?php echo $message; ?>

    <?php if(empty($message)): ?>
    <div class="form-box">
        <form action="request_form.php" method="POST" enctype="multipart/form-data">
            <label>ประเภทบริการ:</label>
            <select name="category" required>
                <option value="ซ่อมประปา">💧 ซ่อมประปา</option>
                <option value="ซ่อมไฟฟ้า">⚡ ซ่อมไฟฟ้า</option>
                <option value="ทำความสะอาด">🧹 ทำความสะอาด</option>
                <option value="อื่นๆ">อื่นๆ</option>
            </select>
            
            <label>รายละเอียดปัญหา:</label>
            <textarea name="description" rows="3" required placeholder="อธิบายปัญหาที่พบ หรือระบุสิ่งที่ต้องการให้ช่วย..."></textarea>
            
            <!-- 🌟 กล่องนัดหมายวัน-เวลาที่เพิ่มเข้ามา -->
            <div class="appointment-box">
                <h4 style="margin-top: 0; margin-bottom: 15px; color: #3b82f6;">📅 ระบุวันและเวลาที่สะดวกให้เข้าห้อง</h4>
                <div class="flex-row">
                    <div class="flex-col">
                        <label>วันที่สะดวก:</label>
                        <input type="date" name="preferredDate" id="preferredDate" required min="<?php echo (date('H:i') >= '17:00') ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d'); ?>" value="<?php echo (date('H:i') >= '17:00') ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d'); ?>">
                    </div>
                    <div class="flex-col">
                        <label>เวลาที่สะดวก (ชั่วโมงทำงาน 09:00 - 17:00):</label>
                        <input type="time" name="preferredTime" id="preferredTime" required min="09:00" max="17:00">
                    </div>
                </div>
                <label class="checkbox-container">
                    <input type="checkbox" name="allowEntry" value="1" required>
                    <span>🔑 ยินยอมให้ช่าง/แม่บ้าน เข้าห้องได้หากฉันไม่อยู่ <strong style="color: red;">*</strong></span>
                </label>
            </div>
            
            <label>แนบรูปภาพประกอบ (ถ้ามี):</label>
            <input type="file" name="request_image" accept="image/*" style="background: white;">

            <button type="submit" name="submit_request">ส่งเรื่องแจ้งซ่อม</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('preferredDate');
    const timeInput = document.getElementById('preferredTime');

    function updateMinTime() {
        if (!dateInput || !timeInput) return;
        const now = new Date();
        const pad = n => String(n).padStart(2, '0');
        const todayStr = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
        const currentTimeStr = `${pad(now.getHours())}:${pad(now.getMinutes())}`;

        if (dateInput.value === todayStr) {
            // วันนี้: ต้องไม่เร็วกว่าเวลาปัจจุบัน และเริ่มไม่ก่อน 09:00
            const minTime = currentTimeStr > '09:00' ? currentTimeStr : '09:00';
            timeInput.min = minTime;
            if (timeInput.value && timeInput.value < minTime) {
                timeInput.value = minTime;
            }
        } else {
            // วันอื่น: เลือกได้ตั้งแต่ 09:00 - 17:00
            timeInput.min = '09:00';
        }
    }

    if (dateInput && timeInput) {
        dateInput.addEventListener('change', updateMinTime);
        timeInput.addEventListener('change', function() {
            updateMinTime();
            if (timeInput.min && timeInput.value < timeInput.min) {
                alert('ไม่สามารถเลือกเวลาที่ผ่านมาแล้วได้ กรุณาเลือกเวลาปัจจุบันหรือเวลาหลังจากนี้');
                timeInput.value = timeInput.min;
            }
        });
        updateMinTime();
        if (!timeInput.value && timeInput.min) {
            timeInput.value = timeInput.min;
        }
    }
});
</script>
</body>
</html>