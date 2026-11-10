<?php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// เด้งไปยังหน้า Dashboard ของแต่ละ Role อัตโนมัติ
if ($userRole === 'Admin') {
    header("Location: admin_dashboard.php");
    exit();
} elseif (in_array($userRole, ['Staff', 'Technician', 'Cleaner'])) {
    header("Location: staff_dashboard.php");
    exit();
} elseif ($userRole === 'Tenant') {
    header("Location: tenant_dashboard.php");
    exit();
}

// ดึงข้อมูลผู้เช่าและห้องที่พักอยู่ พร้อมรายละเอียดสัญญา
$stmtTenantInfo = $conn->prepare("
    SELECT u.*, r.roomNumber, r.roomType, r.rentPrice, r.floor, l.startDate, l.endDate, l.leaseId
    FROM User u
    LEFT JOIN Lease l ON u.userId = l.tenantId AND l.status = 'Active'
    LEFT JOIN Room r ON l.roomId = r.roomId
    WHERE u.userId = ?
");
$stmtTenantInfo->execute([$userId]);
$tenantData = $stmtTenantInfo->fetch(PDO::FETCH_ASSOC);

// ดึงบิลล่าสุดที่ยังค้างชำระ (Unpaid หรือ Pending)
$stmtInvoice = $conn->prepare("
    SELECT * FROM Invoice i
    JOIN Lease l ON i.leaseId = l.leaseId
    WHERE l.tenantId = ? AND i.paymentStatus IN ('Unpaid', 'Pending')
    ORDER BY i.invoiceId DESC LIMIT 1
");
$stmtInvoice->execute([$userId]);
$latestInvoice = $stmtInvoice->fetch(PDO::FETCH_ASSOC);

// ดึงประวัติการแจ้งซ่อมล่าสุดของผู้เช่า
$stmtReqs = $conn->prepare("
    SELECT * FROM ServiceRequest 
    WHERE tenantId = ? 
    ORDER BY requestId DESC LIMIT 3
");
$stmtReqs->execute([$userId]);
$myRequests = $stmtReqs->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>jasmin</title>
    <style>
        :root {
            --sidebar-bg: #0f172a;
            --main-bg: #f8fafc;
            --card-bg: #ffffff;
            --primary: #0ea5e9;
            --success: #10b981;
            --warning: #f59e0b;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius: 12px;
        }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: var(--main-bg); margin: 0; display: flex; color: var(--text-dark); }
        
        /* Sidebar ด้านซ้าย */
        .sidebar { width: 260px; background-color: var(--sidebar-bg); height: 100vh; position: fixed; display: flex; flex-direction: column; justify-content: space-between; padding: 20px; box-sizing: border-box; color: white; }
        .sidebar-brand { font-size: 18px; font-weight: bold; display: flex; align-items: center; gap: 10px; margin-bottom: 30px; color: #38bdf8; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; flex-grow: 1; }
        .sidebar-menu li { margin-bottom: 8px; }
        .sidebar-menu a { color: #94a3b8; text-decoration: none; display: flex; align-items: center; gap: 12px; padding: 12px 15px; border-radius: 8px; font-weight: 500; transition: 0.2s; }
        .sidebar-menu a:hover, .sidebar-menu a.active { background-color: #1e293b; color: white; }
        .support-box { background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; font-size: 12px; color: #94a3b8; }
        .support-btn { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 8px; width: 100%; border-radius: 6px; margin-top: 10px; font-weight: bold; cursor: pointer; }

        /* พื้นที่เนื้อหาหลักด้านขวา */
        .main-content { margin-left: 260px; flex-grow: 1; padding: 30px; box-sizing: border-box; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .user-profile-pill { background: white; padding: 8px 15px; border-radius: 30px; display: flex; align-items: center; gap: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); font-weight: 600; }

        /* Grid Layout */
        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .card { background: var(--card-bg); border-radius: var(--radius); padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border: 1px solid var(--border); margin-bottom: 20px; }

        /* บิลแจ้งหนี้เด่น (การ์ดสีเขียวเข้ม) */
        .invoice-banner { background: linear-gradient(135deg, #059669, #047857); color: white; border-radius: var(--radius); padding: 25px; position: relative; }
        .invoice-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 20px; background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; text-align: center; }
        .pay-btn { background: white; color: #047857; border: none; padding: 8px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; float: right; }

        /* ปุ่มเมนูลัดตรงกลาง */
        .quick-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
        .action-card { padding: 20px; border-radius: var(--radius); color: white; text-decoration: none; font-weight: bold; display: flex; flex-direction: column; gap: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .action-clean { background: linear-gradient(135deg, #10b981, #059669); }
        .action-repair { background: linear-gradient(135deg, #f97316, #ea580c); }
        .action-history { background: linear-gradient(135deg, #0ea5e9, #0284c7); }

        /* ตารางประวัติและข้อมูลอื่นๆ */
        .section-title { font-size: 16px; font-weight: bold; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 14px; }
        .logout-link { color: #ef4444; text-decoration: none; font-weight: 600; display: block; text-align: center; margin-top: 15px; }
    </style>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body>

    <!-- Sidebar ด้านซ้าย -->
    <div class="sidebar">
        <div>
            <div class="sidebar-brand">
                🏢 <span>หอพักจัสมิน</span>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php" class="active">🏠 หน้าหลัก</a></li>
                <li><a href="tenant_invoice.php">💰 บิลค่าใช้จ่าย</a></li>
                <li><a href="service_request.php">🛠️ แจ้งซ่อม / บริการ</a></li>
            </ul>
        </div>
        <div>
            <div class="support-box">
                <div style="font-weight: bold; margin-bottom: 4px;">SUPPORT</div>
                เจ้าหน้าที่ดูแลอาคารพร้อมให้ความช่วยเหลือ
                <button class="support-btn" onclick="alert('ติดต่อ Admin: 02-xxx-xxxx')">ติดต่อแอดมิน</button>
            </div>
            <a href="logout.php" class="logout-link">ออกจากระบบ</a>
        </div>
    </div>

    <!-- Main Content ด้านขวา -->
    <div class="main-content">
        <div class="top-header">
            <div>
                <div style="font-size: 13px; color: var(--text-muted);">ยินดีต้อนรับกลับมา</div>
                <h2 style="margin: 0;">Dashboard ลูกบ้าน</h2>
            </div>
            <div class="user-profile-pill">
                <span>👤 <?php echo $tenantData['fullName'] ?? 'ผู้เช่า'; ?></span>
                <span style="font-size: 12px; color: var(--text-muted); font-weight: normal;">ห้อง <?php echo $tenantData['roomNumber'] ?? '-'; ?></span>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- ฝั่งซ้าย: บิล และ เมนูลัด -->
            <div>
                <!-- ใบแจ้งหนี้รอบปัจจุบัน -->
                <div class="invoice-banner">
                    <button class="pay-btn" onclick="location.href='tenant_invoice.php'">ชำระเงิน</button>
                    <div style="font-size: 14px; opacity: 0.9;">ใบแจ้งหนี้รอบปัจจุบัน</div>
                    <div style="font-size: 32px; font-weight: bold; margin: 5px 0;">
                        ฿<?php echo isset($latestInvoice['totalAmount']) ? number_format($latestInvoice['totalAmount'], 2) : '0.00'; ?>
                    </div>
                    <div class="invoice-grid">
                        <div>
                            <div style="font-size: 11px; opacity: 0.8;">ค่าห้อง</div>
                            <div style="font-weight: bold;"><?php echo number_format($tenantData['rentPrice'] ?? 0); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; opacity: 0.8;">ค่าน้ำ</div>
                            <div style="font-weight: bold;"><?php echo number_format($latestInvoice['waterCost'] ?? 0); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; opacity: 0.8;">ค่าไฟ</div>
                            <div style="font-weight: bold;"><?php echo number_format($latestInvoice['electricCost'] ?? 0); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; opacity: 0.8;">รวมทั้งสิ้น</div>
                            <div style="font-weight: bold;"><?php echo number_format($latestInvoice['totalAmount'] ?? 0); ?></div>
                        </div>
                    </div>
                </div>

                <!-- เมนูลัด 3 ปุ่ม -->
                <div class="quick-actions" style="margin-top: 20px;">
                    <a href="service_request.php" class="action-card action-clean">
                        <span>🧹 แจ้งทำความสะอาด</span>
                        <span style="font-size: 11px; opacity: 0.8; font-weight: normal;">ดำเนินการได้ทันที</span>
                    </a>
                    <a href="service_request.php" class="action-card action-repair">
                        <span>🛠️ แจ้งซ่อมแซม</span>
                        <span style="font-size: 11px; opacity: 0.8; font-weight: normal;">ดำเนินการได้ทันที</span>
                    </a>
                    <a href="service_request.php" class="action-card action-history">
                        <span>📋 ประวัติการแจ้งงาน</span>
                        <span style="font-size: 11px; opacity: 0.8; font-weight: normal;">ตรวจสอบสถานะ</span>
                    </a>
                </div>

                <!-- ประวัติการแจ้งงานล่าสุด -->
                <div class="card">
                    <div class="section-title">
                        <span>ประวัติการแจ้งงานล่าสุด</span>
                        <a href="service_request.php" style="font-size: 13px; color: var(--primary); text-decoration: none;">ดูทั้งหมด</a>
                    </div>
                    <?php if(count($myRequests) > 0): ?>
                        <?php foreach($myRequests as $req): ?>
                        <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border);">
                            <div>
                                <div style="font-weight: bold; font-size: 14px;"><?php echo $req['category']; ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);"><?php echo $req['requestDate']; ?></div>
                            </div>
                            <div>
                                <span style="font-size: 12px; padding: 4px 10px; border-radius: 12px; background: #e0f2fe; color: #0369a1; font-weight: 600;">
                                    <?php echo $req['status']; ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 15px;">ยังไม่มีประวัติการแจ้งซ่อม</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ฝั่งขวา: ข้อมูลส่วนตัว & รายละเอียดสัญญา -->
            <div>
                <!-- ข้อมูลส่วนตัว -->
                <div class="card">
                    <div class="section-title">ข้อมูลส่วนตัว</div>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px; background: #f1f5f9; padding: 12px; border-radius: 8px;">
                        <div style="background: var(--primary); color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                            👤
                        </div>
                        <div>
                            <div style="font-weight: bold;"><?php echo $tenantData['fullName'] ?? '-'; ?></div>
                            <div style="font-size: 12px; color: var(--text-muted);">ห้อง <?php echo $tenantData['roomNumber'] ?? '-'; ?></div>
                        </div>
                    </div>
                    <div class="info-row">
                        <span style="color: var(--text-muted);">โทรศัพท์</span>
                        <span><?php echo $tenantData['phone'] ?? '-'; ?></span>
                    </div>
                    <div class="info-row">
                        <span style="color: var(--text-muted);">อีเมล</span>
                        <span><?php echo $tenantData['email'] ?? '-'; ?></span>
                    </div>
                </div>

                <!-- รายละเอียดสัญญาเช่า -->
                <div class="card">
                    <div class="section-title">รายละเอียดสัญญาเช่า</div>
                    <div class="info-row">
                        <span style="color: var(--text-muted);">วันที่เข้าพัก</span>
                        <span><?php echo $tenantData['startDate'] ?? '-'; ?></span>
                    </div>
                    <div class="info-row">
                        <span style="color: var(--text-muted);">รวมค่าเช่า/เดือน</span>
                        <span>฿<?php echo number_format($tenantData['rentPrice'] ?? 0); ?></span>
                    </div>
                    <div class="info-row">
                        <span style="color: var(--text-muted);">ประเภทห้อง</span>
                        <span><?php echo $tenantData['roomType'] ?? '-'; ?></span>
                    </div>
                    <div class="info-row" style="border: none;">
                        <span style="color: var(--text-muted);">ชั้น</span>
                        <span>ชั้น <?php echo $tenantData['floor'] ?? '-'; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>