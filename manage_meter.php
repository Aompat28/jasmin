<?php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$message = "";

// 1. ระบบบันทึกมิเตอร์น้ำ-ไฟ
if (isset($_POST['save_meter'])) {
    $roomId = $_POST['roomId'];
    $readingDate = $_POST['readingDate']; 
    $billingMonthFull = $_POST['billingMonth']; 

    // แยกปีและเดือน
    if (!empty($billingMonthFull)) {
        list($year, $month) = explode('-', $billingMonthFull);
    } else {
        $year = date('Y');
        $month = date('m');
    }

    $waterUnit = floatval($_POST['waterUnit']);
    $electricUnit = floatval($_POST['electricUnit']);

    try {
        // 🌟 ขั้นตอนที่ 1: ค้นหาสัญญาเช่า (leaseId) ที่กำลัง Active ของห้องนี้
        $stmtCheck = $conn->prepare("SELECT leaseId FROM lease WHERE roomId = ? AND status = 'Active' LIMIT 1");
        $stmtCheck->execute([$roomId]);
        $activeLease = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$activeLease) {
            // ถ้าห้องว่าง ไม่มีสัญญาเช่า จะไม่ให้จดมิเตอร์
            $message = "<div style='color: red; padding: 12px; background: #f8d7da; margin-bottom: 20px; border-radius: 6px; font-weight: bold;'>❌ บันทึกไม่ได้: ห้องนี้ยังไม่มีผู้เช่า (ไม่พบสัญญาเช่าที่กำลังใช้งาน)</div>";
        } else {
            $leaseId = $activeLease['leaseId'];

            // 🌟 ขั้นตอนที่ 2: บันทึกข้อมูล โดยแนบ leaseId เข้าไปด้วยตามที่ฐานข้อมูลต้องการ
            $stmt = $conn->prepare("
                INSERT INTO meterreading (leaseId, roomId, recordedAt, billingMonth, billingYear, waterUsage, electricUsage) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$leaseId, $roomId, $readingDate, $month, $year, $waterUnit, $electricUnit]);
            
            $message = "<div style='color: green; padding: 12px; background: #d4edda; margin-bottom: 20px; border-radius: 6px; font-weight: bold;'>✅ บันทึกเลขมิเตอร์ประจำวันที่ " . date("d/m/Y", strtotime($readingDate)) . " สำเร็จ!</div>";
        }
    } catch(PDOException $e) {
        $message = "<div style='color: red; padding: 12px; background: #f8d7da; margin-bottom: 20px; border-radius: 6px; font-weight: bold;'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
    }
}

// 2. ดึงข้อมูลห้องพักทั้งหมด
$rooms = $conn->query("SELECT * FROM room ORDER BY roomNumber ASC")->fetchAll(PDO::FETCH_ASSOC);

// 3. ดึงประวัติการจดมิเตอร์มาแสดง
try {
    $readings = $conn->query("
        SELECT m.*, r.roomNumber 
        FROM meterreading m 
        JOIN room r ON m.roomId = r.roomId 
        ORDER BY m.meterReadingId DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $readings = $conn->query("SELECT * FROM meterreading ORDER BY 1 DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {
        $readings = [];
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>jasmin</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f8fafc; padding: 30px; color: #1e293b; }
        .container { max-width: 950px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .back-btn { text-decoration: none; color: #4f46e5; font-weight: bold; display: inline-block; margin-bottom: 20px; }
        label { display: block; margin-top: 15px; margin-bottom: 5px; font-weight: bold; font-size: 14px; }
        input, select { padding: 10px; width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
        button { background: #3b82f6; color: white; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; margin-top: 20px; font-size: 14px; }
        button:hover { background: #2563eb; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #e2e8f0; padding: 12px; text-align: left; font-size: 14px; }
        th { background: #f1f5f9; color: #334155; }
        .form-box { background: #f8fafc; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 30px; }
    </style>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body>
<div class="container">
    <a href="admin_dashboard.php" class="back-btn">⬅ กลับหน้าแอดมิน</a>
    <h2 style="margin-top: 0; color: #1e3a8a;">💧⚡ ระบบจดมิเตอร์น้ำ-ไฟ</h2>
    
    <?php echo $message; ?>

    <form action="" method="POST" class="form-box">
        <h3 style="margin-top: 0; color: #3b82f6;">+ บันทึกหน่วยมิเตอร์</h3>
        
        <label>เลือกห้องพัก (เฉพาะห้องที่มีผู้เช่า):</label>
        <select name="roomId" required>
            <option value="">-- เลือกห้อง --</option>
            <?php foreach($rooms as $r): ?>
                <option value="<?php echo $r['roomId']; ?>">ห้อง <?php echo $r['roomNumber']; ?></option>
            <?php endforeach; ?>
        </select>

        <div style="display: flex; gap: 15px;">
            <div style="flex:1;">
                <label>วันที่จดมิเตอร์:</label>
                <input type="date" name="readingDate" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div style="flex:1;">
                <label>รอบเดือน/ปี ที่คิดค่าใช้จ่าย:</label>
                <input type="month" name="billingMonth" required>
            </div>
        </div>

        <div style="display: flex; gap: 15px;">
            <div style="flex:1;">
                <label>หน่วยน้ำที่ใช้:</label>
                <input type="number" step="0.01" name="waterUnit" placeholder="0.00" required>
            </div>
            <div style="flex:1;">
                <label>หน่วยไฟที่ใช้:</label>
                <input type="number" step="0.01" name="electricUnit" placeholder="0.00" required>
            </div>
        </div>

        <button type="submit" name="save_meter">บันทึกมิเตอร์</button>
    </form>

    <h3 style="color: #334155;">ประวัติการจดมิเตอร์</h3>
    <table>
        <tr>
            <th>ห้อง</th>
            <th>วันที่จด</th>
            <th>รอบเดือน/ปี</th>
            <th>หน่วยน้ำ</th>
            <th>หน่วยไฟ</th>
        </tr>
        <?php if(count($readings) > 0): foreach($readings as $rd): ?>
        <tr>
            <td><b>ห้อง <?php echo $rd['roomNumber'] ?? '-'; ?></b></td>
            <td><?php echo !empty($rd['recordedAt']) ? date("d/m/Y", strtotime($rd['recordedAt'])) : '-'; ?></td>
            <td><?php echo ($rd['billingMonth'] ?? '-') . '/' . ($rd['billingYear'] ?? '-'); ?></td>
            <td><?php echo number_format($rd['waterUsage'] ?? 0, 2); ?> หน่วย</td>
            <td><?php echo number_format($rd['electricUsage'] ?? 0, 2); ?> หน่วย</td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="5" style="text-align:center; color:#64748b; padding:20px;">ยังไม่มีประวัติการจดมิเตอร์</td></tr>
        <?php endif; ?>
    </table>
</div>
</body>
</html>