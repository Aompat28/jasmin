<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบสิทธิ์ ต้องเป็น Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$message = "";

// --- 1. สร้างสัญญาเช่า (และยึดห้อง) ---
if (isset($_POST['create_lease'])) {
    $roomId = $_POST['roomId'];
    $tenantId = $_POST['tenantId'];
    $startDate = $_POST['startDate'];
    $endDate = $_POST['endDate'];
    $depositAmount = !empty($_POST['depositAmount']) ? floatval($_POST['depositAmount']) : 0;
    $initialWaterMeter = !empty($_POST['initialWaterMeter']) ? min(9999, max(0, floatval($_POST['initialWaterMeter']))) : 0;
    $initialElectricMeter = !empty($_POST['initialElectricMeter']) ? min(9999, max(0, floatval($_POST['initialElectricMeter']))) : 0;
    
    $leaseImage = '';
    if (isset($_FILES['lease_file']) && $_FILES['lease_file']['error'] == 0) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
        $fileName = time() . '_lease_' . basename($_FILES['lease_file']['name']);
        $targetFilePath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['lease_file']['tmp_name'], $targetFilePath)) {
            $leaseImage = $targetFilePath;
        }
    }

    try {
        $stmt = $conn->prepare("INSERT INTO Lease (roomId, tenantId, startDate, endDate, depositAmount, leaseImage, initialWaterMeter, initialElectricMeter, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
        $stmt->execute([$roomId, $tenantId, $startDate, $endDate, $depositAmount, $leaseImage, $initialWaterMeter, $initialElectricMeter]);
        
        // อัปเดตห้องเป็น 'ไม่ว่าง'
        $stmtRoom = $conn->prepare("UPDATE Room SET status = 'Occupied' WHERE roomId = ?");
        $stmtRoom->execute([$roomId]);

        $message = "<div style='color: green; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px;'>✅ ทำสัญญาสำเร็จ! ผู้เช่าได้เข้าอยู่แล้ว (ห้องถูกเปลี่ยนสถานะเป็น Occupied)</div>";
    } catch(PDOException $e) {
        $message = "<div style='color: red; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px;'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
    }
}

// --- 2. อัปเดตไฟล์สัญญาย้อนหลัง ---
if (isset($_POST['update_old_lease'])) {
    $leaseId = $_POST['leaseId'];
    if (isset($_FILES['old_lease_file']) && $_FILES['old_lease_file']['error'] == 0) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
        $fileName = time() . '_oldlease_' . basename($_FILES['old_lease_file']['name']);
        $targetFilePath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['old_lease_file']['tmp_name'], $targetFilePath)) {
            $stmtUp = $conn->prepare("UPDATE Lease SET leaseImage = ? WHERE leaseId = ?");
            $stmtUp->execute([$targetFilePath, $leaseId]);
            $message = "<div style='color: green; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px;'>✅ แนบไฟล์สำเร็จ!</div>";
        }
    }
}

// --- 2.1 อัปเดตเลขมิเตอร์เริ่มต้นของสัญญา ---
if (isset($_POST['update_initial_meters'])) {
    $leaseId = intval($_POST['leaseId']);
    $initialWaterMeter = min(9999, max(0, floatval($_POST['initialWaterMeter'] ?? 0)));
    $initialElectricMeter = min(9999, max(0, floatval($_POST['initialElectricMeter'] ?? 0)));
    try {
        $stmtUp = $conn->prepare("UPDATE Lease SET initialWaterMeter = ?, initialElectricMeter = ? WHERE leaseId = ?");
        $stmtUp->execute([$initialWaterMeter, $initialElectricMeter, $leaseId]);
        $message = "<div style='color: green; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px;'>✅ บันทึกเลขมิเตอร์เริ่มต้นสัญญาสำเร็จ!</div>";
    } catch(PDOException $e) {
        $message = "<div style='color: red; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px;'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
    }
}

// --- 3. ยกเลิกสัญญาเช่า (และคืนห้องว่าง) ---
if (isset($_POST['delete_lease'])) {
    $leaseId = $_POST['leaseId'];
    $roomId = $_POST['roomId'];
    try {
        $stmtDel = $conn->prepare("DELETE FROM Lease WHERE leaseId = ?");
        $stmtDel->execute([$leaseId]);
        
        // คืนสถานะห้องเป็น 'ว่าง'
        $stmtRoom = $conn->prepare("UPDATE Room SET status = 'Available' WHERE roomId = ?");
        $stmtRoom->execute([$roomId]);

        $message = "<div style='color: green; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px;'>🗑️ ลบสัญญาสำเร็จ! (คืนห้องเป็นสถานะว่างเรียบร้อย)</div>";
    } catch(PDOException $e) {
        $message = "<div style='color: red; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px;'>❌ ลบไม่ได้ เนื่องจากมีบิลค้างชำระผูกอยู่กับสัญญานี้</div>";
    }
}

// 🌟 ดึงเฉพาะห้องที่ "ว่างอยู่ (Available)" เท่านั้นมาให้เลือกทำสัญญา
$available_rooms = $conn->query("SELECT * FROM Room WHERE status = 'Available' ORDER BY roomNumber ASC")->fetchAll(PDO::FETCH_ASSOC);

// ดึงผู้ใช้งานที่เป็น Tenant
$tenants = $conn->query("SELECT * FROM User WHERE role = 'Tenant' ORDER BY fullName ASC")->fetchAll(PDO::FETCH_ASSOC);

// ดึงข้อมูลสัญญาเช่ามาแสดงในตาราง
$leases = $conn->query("
    SELECT l.*, r.roomNumber, u.fullName 
    FROM Lease l 
    JOIN Room r ON l.roomId = r.roomId 
    JOIN User u ON l.tenantId = u.userId 
    ORDER BY l.leaseId DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>jasmin</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: #f8fafc; padding: 30px; color: #1e293b; }
        .container { max-width: 1100px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .back-btn { text-decoration: none; color: #4f46e5; font-weight: bold; display: inline-block; margin-bottom: 20px; }
        .form-box { background: #f1f5f9; padding: 20px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #e2e8f0; }
        .flex-row { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px; }
        .form-group { flex: 1; min-width: 200px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px; }
        input, select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
        .btn-submit { background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #e2e8f0; padding: 12px; text-align: left; font-size: 13px; vertical-align: middle; }
        th { background: #f8fafc; color: #334155; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; color: white; display: inline-block; text-decoration: none; }
        .upload-mini-form { display: flex; flex-direction: column; gap: 5px; background: #f8fafc; padding: 8px; border-radius: 6px; border: 1px dashed #cbd5e1; margin-top: 8px; }
        .upload-mini-form input[type="file"] { font-size: 11px; padding: 4px; border: none; background: white; width: 100%; }
        .upload-mini-form button { background: #3b82f6; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 11px; cursor: pointer; width: fit-content; }
        .btn-delete { background: #ef4444; color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: bold; margin-top: 5px; width: 100%; }
    </style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body>

<div class="container">
    <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 24px;">
        <a href="admin_dashboard.php" style="display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; background: white; border: 1px solid #cbd5e1; border-radius: 12px; color: #475569; text-decoration: none; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.06);" title="กลับหน้าหลักผู้ดูแลระบบ">
            <span style="font-size: 20px; font-weight: bold; line-height: 1;">←</span>
        </a>
        <div>
            <h2 style="margin: 0; font-size: 22px; color: #1e293b;">📄 ระบบจัดการสัญญาเช่า</h2>
            <p style="margin: 4px 0 0 0; font-size: 13px; color: #64748b;">สร้างสัญญาเช่าใหม่ จัดการเอกสารสัญญา และสถานะการเช่า</p>
        </div>
    </div>
    
    <?php echo $message; ?>

    <div class="form-box">
        <h3 style="margin-top: 0; color: #3b82f6;">+ สร้างสัญญาเช่าใหม่</h3>
        <form action="" method="POST" enctype="multipart/form-data">
            <div class="flex-row">
                <div class="form-group">
                    <label>เลือกห้องพัก (เฉพาะห้องว่าง):</label>
                    <select name="roomId" required>
                        <?php if(count($available_rooms) > 0): ?>
                            <option value="">-- เลือกห้องว่าง --</option>
                            <?php foreach($available_rooms as $r): ?>
                                <option value="<?php echo $r['roomId']; ?>">ห้อง <?php echo $r['roomNumber']; ?> (ว่าง)</option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled selected>❌ ไม่มีห้องว่างในขณะนี้ (กรุณาสร้างห้องใหม่)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>เลือกผู้เช่า:</label>
                    <select name="tenantId" required>
                        <option value="">-- เลือกผู้เช่า --</option>
                        <?php foreach($tenants as $t): ?>
                            <option value="<?php echo $t['userId']; ?>"><?php echo htmlspecialchars($t['fullName']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex-row">
                <div class="form-group"><label>เริ่มสัญญา:</label><input type="date" name="startDate" required></div>
                <div class="form-group"><label>สิ้นสุดสัญญา:</label><input type="date" name="endDate" required></div>
            </div>

            <div class="flex-row">
                <div class="form-group"><label>เงินมัดจำ (บาท):</label><input type="number" step="0.01" name="depositAmount" value="0.00"></div>
                <div class="form-group"><label>อัปโหลดรูปสัญญา (ถ้ามี):</label><input type="file" name="lease_file" accept="image/*,.pdf"></div>
            </div>

            <div class="flex-row">
                <div class="form-group"><label>เลขมิเตอร์น้ำประปา เริ่มต้น (4 หลัก):</label><input type="number" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" name="initialWaterMeter" placeholder="เช่น 0000" value="0" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4);"></div>
                <div class="form-group"><label>เลขมิเตอร์ไฟฟ้า เริ่มต้น (4 หลัก):</label><input type="number" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" name="initialElectricMeter" placeholder="เช่น 0000" value="0" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4);"></div>
            </div>

            <!-- ถ้าไม่มีห้องว่าง ให้ปุ่มกดไม่ได้ เพื่อกันพัง -->
            <button type="submit" name="create_lease" class="btn-submit" <?php echo count($available_rooms) == 0 ? 'disabled style="background:#cbd5e1; cursor:not-allowed;"' : ''; ?>>
                บันทึกสัญญาเช่า (และยึดห้อง)
            </button>
        </form>
    </div>

    <h3>รายชื่อสัญญาเช่า</h3>
    <table>
        <tr>
            <th>รหัส</th><th>ห้อง</th><th>ผู้เช่า</th><th>ระยะเวลา</th><th>เงินมัดจำ</th><th>มิเตอร์เริ่มต้น (น้ำ/ไฟ)</th><th>เอกสารแนบ</th><th style="text-align:center;">จัดการ</th>
        </tr>
        <?php if(count($leases) > 0): foreach($leases as $l): ?>
        <tr>
            <td>#LSE-<?php echo $l['leaseId']; ?></td>
            <td><b>ห้อง <?php echo $l['roomNumber']; ?></b></td>
            <td><?php echo htmlspecialchars($l['fullName']); ?></td>
            <td><?php echo date("d/m/y", strtotime($l['startDate'])); ?> ถึง <?php echo date("d/m/y", strtotime($l['endDate'])); ?></td>
            <td><?php echo number_format($l['depositAmount'], 2); ?> ฿</td>
            <td>
                <form action="" method="POST" style="margin:0; display:flex; flex-direction:column; gap:4px; min-width:120px;">
                    <input type="hidden" name="leaseId" value="<?php echo $l['leaseId']; ?>">
                    <div style="display:flex; align-items:center; gap:4px; font-size:11px;">
                        <span style="color:#0284c7; font-weight:bold; width:35px;">💧น้ำ:</span>
                        <input type="number" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" name="initialWaterMeter" value="<?php echo intval($l['initialWaterMeter'] ?? 0); ?>" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4);" style="width:75px; padding:3px 5px; font-size:11px; border:1px solid #cbd5e1; border-radius:4px; font-family:monospace;">
                    </div>
                    <div style="display:flex; align-items:center; gap:4px; font-size:11px;">
                        <span style="color:#d97706; font-weight:bold; width:35px;">⚡ไฟ:</span>
                        <input type="number" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" name="initialElectricMeter" value="<?php echo intval($l['initialElectricMeter'] ?? 0); ?>" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4);" style="width:75px; padding:3px 5px; font-size:11px; border:1px solid #cbd5e1; border-radius:4px; font-family:monospace;">
                    </div>
                    <button type="submit" name="update_initial_meters" style="background:#4f46e5; color:white; border:none; padding:4px 8px; border-radius:4px; font-size:11px; cursor:pointer; font-weight:bold; margin-top:2px;">💾 บันทึกมิเตอร์</button>
                </form>
            </td>
            <td>
                <?php if(!empty($l['leaseImage'])): ?>
                    <a href="<?php echo htmlspecialchars($l['leaseImage']); ?>" target="_blank" class="badge" style="background:#10b981;">📄 ดูไฟล์สัญญา</a>
                    <form action="" method="POST" enctype="multipart/form-data" class="upload-mini-form">
                        <input type="hidden" name="leaseId" value="<?php echo $l['leaseId']; ?>">
                        <input type="file" name="old_lease_file" accept="image/*,.pdf" required>
    
                    </form>
                <?php else: ?>
                    <span style="color: #ef4444; font-size: 11px;">❌ ยังไม่มีไฟล์</span>
                    <form action="" method="POST" enctype="multipart/form-data" class="upload-mini-form">
                        <input type="hidden" name="leaseId" value="<?php echo $l['leaseId']; ?>">
                        <input type="file" name="old_lease_file" accept="image/*,.pdf" required>
                        <button type="submit" name="update_old_lease">📤 แนบสัญญา</button>
                    </form>
                <?php endif; ?>
            </td>
            <td>
                <form action="" method="POST" style="margin: 0;" onsubmit="return confirm('ลบสัญญานี้? (ระบบจะคืนสถานะห้องเป็นว่างอัตโนมัติ)');">
                    <input type="hidden" name="leaseId" value="<?php echo $l['leaseId']; ?>">
                    <input type="hidden" name="roomId" value="<?php echo $l['roomId']; ?>">
                    <button type="submit" name="delete_lease" class="btn-delete">🗑️ ยกเลิกสัญญา</button>
                </form>
            </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="8" style="text-align:center; padding:20px;">ยังไม่มีข้อมูลสัญญาเช่า</td></tr>
        <?php endif; ?>
    </table>
</div>

</body>
</html>