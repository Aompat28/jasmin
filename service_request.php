<?php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userRole = $_SESSION['role'];
$userId = $_SESSION['user_id'];
$message = "";

// หากเป็น Admin ให้ไปหน้าจัดการงานแจ้งซ่อมบำรุงเวอร์ชันใหม่ (admin_requests.php)
if ($userRole === 'Admin') {
    header("Location: admin_requests.php");
    exit();
}

$backPage = "dashboard.php";
if ($userRole === 'Tenant') $backPage = "tenant_dashboard.php";
elseif ($userRole === 'Technician') $backPage = "staff_dashboard.php";
elseif (in_array($userRole, ['Housekeeper', 'Cleaner'])) $backPage = "staff_dashboard.php";

// 1. กรณี Admin กดมอบหมายงาน
if (isset($_POST['assign_job']) && $userRole === 'Admin') {
    $requestId = $_POST['requestId'];
    $assignedTo = $_POST['assignedTo'];
    $stmtAssign = $conn->prepare("UPDATE ServiceRequest SET assignedTo = ?, status = 'Pending' WHERE requestId = ?");
    $stmtAssign->execute([$assignedTo, $requestId]);
    $message = "<div class='alert-box' style='color: green; padding: 10px; background: #d4edda; margin-bottom: 15px; border-radius: 5px; transition: opacity 0.5s;'>✅ มอบหมายงานเรียบร้อยแล้ว!</div>";
}

// 2. กรณีลูกบ้าน (Tenant) ส่งคอมเม้นต์ / ติชมงาน (เมื่อช่าง/แม่บ้านส่งงานแล้ว หรือ Completed)
if (isset($_POST['submit_comment']) && $userRole === 'Tenant') {
    $requestId = $_POST['requestId'];
    $comment = trim($_POST['tenantComment']);

    $stmtCheck = $conn->prepare("UPDATE ServiceRequest SET tenantComment = ?, commentedAt = NOW() WHERE requestId = ? AND tenantId = ? AND status = 'Completed'");
    $stmtCheck->execute([$comment, $requestId, $userId]);
    
    $message = "<div class='alert-box' style='color: green; padding: 10px; background: #d4edda; margin-bottom: 15px; border-radius: 5px; transition: opacity 0.5s;'>✅ ขอบคุณสำหรับความคิดเห็นและการติชมบริการครับ! (ข้อความนี้จะเห็นเฉพาะคุณและแอดมินเท่านั้น ช่าง/แม่บ้านจะไม่เห็น)</div>";
}

// ดึงข้อมูลทั้งหมด รวมถึงข้อมูลพนักงาน (staff)
if ($userRole === 'Admin') {
    $reqs = $conn->query("
        SELECT sr.*, r.roomNumber, u.fullName as tenantName, staff.fullName as staffName, staff.role as staffRole
        FROM ServiceRequest sr 
        JOIN Room r ON sr.roomId = r.roomId 
        JOIN User u ON sr.tenantId = u.userId 
        LEFT JOIN User staff ON sr.assignedTo = staff.userId
        ORDER BY sr.requestId DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $staffs = $conn->query("SELECT userId, fullName, role FROM User WHERE role IN ('Technician', 'Housekeeper', 'Cleaner')")->fetchAll(PDO::FETCH_ASSOC);

} elseif ($userRole === 'Tenant') {
    $stmt = $conn->prepare("
        SELECT sr.*, r.roomNumber, staff.fullName as staffName, staff.role as staffRole
        FROM ServiceRequest sr 
        JOIN Room r ON sr.roomId = r.roomId 
        LEFT JOIN User staff ON sr.assignedTo = staff.userId
        WHERE sr.tenantId = ? 
        ORDER BY sr.requestId DESC
    ");
    $stmt->execute([$userId]);
    $reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>jasmin</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: #f8fafc; padding: 30px; color: #1e293b; }
        .container { max-width: 1050px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .back-btn { text-decoration: none; color: #4f46e5; font-weight: bold; display: inline-block; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #e2e8f0; padding: 12px; text-align: left; font-size: 14px; vertical-align: top; }
        th { background: #f1f5f9; }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; color: white; }
        .img-btn { color: white; padding: 4px 8px; text-decoration: none; border-radius: 4px; font-size: 12px; display: inline-block; margin-top: 5px; font-weight: bold; }
        .btn-new { background: #4f46e5; color: white; text-decoration: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; font-size: 14px; float: right; }
        .appointment-info { background: #e0f2fe; border: 1px solid #bae6fd; padding: 8px; border-radius: 6px; margin-top: 8px; font-size: 13px; }
        .staff-info { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 6px 10px; border-radius: 6px; margin-top: 8px; font-size: 13px; color: #475569; }
        .comment-box { background: #fdf4ff; border: 1px solid #f5d0fe; padding: 10px; border-radius: 6px; margin-top: 8px; font-size: 13px; }
        .comment-form textarea { width: 100%; padding: 6px; margin-top: 5px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; box-sizing: border-box; }
    </style>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body>
<div class="container">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <a href="<?php echo $backPage; ?>" style="display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; background: white; border: 1px solid #cbd5e1; border-radius: 12px; color: #475569; text-decoration: none; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.06);" title="ย้อนกลับหน้าหลัก">
                <span style="font-size: 20px; font-weight: bold; line-height: 1;">←</span>
            </a>
            <div>
                <h2 style="margin: 0; font-size: 22px;">📋 ประวัติการแจ้งซ่อมและบริการ</h2>
                <span style="font-size: 13px; color: #64748b;">ติดตามความคืบหน้าของงานซ่อมและบริการ</span>
            </div>
        </div>
        <?php if($userRole === 'Tenant'): ?>
            <a href="request_form.php" class="btn-new">+ แจ้งเรื่องใหม่</a>
        <?php endif; ?>
    </div>
    
    <hr style="border: 1px solid #e2e8f0; margin: 15px 0 25px 0;">
    
    <div id="alert-container">
        <?php echo $message; ?>
    </div>

    <table>
        <tr>
            <th width="5%">ID</th>
            <?php if($userRole === 'Admin') echo "<th width='15%'>ผู้แจ้ง (ห้อง)</th>"; ?>
            <th width="15%">ประเภท</th>
            <th width="35%">รายละเอียด & ข้อมูลผู้รับผิดชอบ</th>
            <th width="15%">สถานะ</th>
            <?php if($userRole === 'Admin') echo "<th width='15%'>จ่ายงาน</th>"; ?>
        </tr>
        <?php if(!empty($reqs)): foreach($reqs as $req): ?>
        <tr>
            <td>#<?php echo $req['requestId']; ?></td>
            <?php if($userRole === 'Admin'): ?>
                <td><b><?php echo $req['tenantName']; ?></b> <br><small>(ห้อง <?php echo $req['roomNumber']; ?>)</small></td>
            <?php endif; ?>
            <td><b><?php echo $req['category']; ?></b></td>
            <td>
                <?php echo $req['description']; ?>
                
                <?php if(!empty($req['preferredDate'])): ?>
                <div class="appointment-info">
                    📅 <b>นัดหมาย:</b> <?php echo date("d/m/Y", strtotime($req['preferredDate'])); ?> (<?php echo $req['preferredTime']; ?>)<br>
                    <?php if($req['allowEntry'] == 1): ?>
                        <span style="color: #059669;">🔑 อนุญาตให้เข้าห้องได้</span>
                    <?php else: ?>
                        <span style="color: #dc2626;">🚫 ไม่อนุญาตให้เข้าห้องถ้าไม่อยู่</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if(!empty($req['staffName'])): ?>
                <div class="staff-info">
                    🛠️ <b>ผู้ปฏิบัติงาน:</b> <?php echo htmlspecialchars($req['staffName']); ?> 
                    <?php 
                        $roleTh = 'พนักงาน';
                        if(in_array($req['staffRole'], ['Housekeeper', 'Cleaner'])) $roleTh = 'แม่บ้าน';
                        if($req['staffRole'] === 'Technician') $roleTh = 'ช่างซ่อม';
                    ?>
                    <span style="font-size: 11px; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;"><?php echo $roleTh; ?></span>
                </div>
                <?php else: ?>
                <div class="staff-info" style="color: #94a3b8; font-style: italic;">
                    🛠️ ผู้ปฏิบัติงาน: ยังไม่ได้มอบหมาย
                </div>
                <?php endif; ?>

                <div style="margin-top: 5px;">
                    <?php if(!empty($req['requestImage'])): ?>
                        <a href="<?php echo htmlspecialchars($req['requestImage']); ?>" target="_blank" class="img-btn" style="background: #ef4444;">🖼️ รูปปัญหา</a>
                    <?php endif; ?>
                    <?php if(!empty($req['completionImage']) && ($userRole !== 'Tenant' || $req['status'] === 'Completed')): ?>
                        <a href="<?php echo htmlspecialchars($req['completionImage']); ?>" target="_blank" class="img-btn" style="background: #10b981;">✅ รูปหลังปฏิบัติงาน</a>
                    <?php elseif($userRole === 'Tenant' && $req['status'] === 'Pending Approval'): ?>
                        <span style="display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa;">⏳ รอแอดมินอนุมัติส่งมอบงาน</span>
                    <?php endif; ?>
                </div>

                <?php 
                $canCommentLegacy = ($req['status'] === 'Completed');
                if ($canCommentLegacy): 
                ?>
                    <?php if(!empty($req['tenantComment']) && in_array($userRole, ['Admin', 'Tenant'])): ?>
                        <div class="comment-box" style="background: #faf5ff; border: 1px solid #e9d5ff; color: #581c87;">
                            💬 <b>ติชมจากผู้เช่า (เฉพาะผู้เช่าและแอดมิน):</b> "<?php echo htmlspecialchars($req['tenantComment']); ?>"
                        </div>
                    <?php elseif($userRole === 'Tenant' && empty($req['tenantComment'])): ?>
                        <div class="comment-box comment-form" style="background: #f8fafc; border-color: #cbd5e1;">
                            <form action="service_request.php" method="POST">
                                <input type="hidden" name="requestId" value="<?php echo $req['requestId']; ?>">
                                <b>💬 ฝากข้อความติชมการบริการ (เฉพาะแอดมินและคุณที่เห็น):</b>
                                <textarea name="tenantComment" rows="2" placeholder="พิมพ์ความคิดเห็นหรือข้อเสนอแนะ..." required></textarea>
                                <button type="submit" name="submit_comment" style="background: #8b5cf6; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-weight: bold; cursor: pointer; margin-top: 5px; font-size: 11px;">ส่งคอมเม้นต์</button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if($req['status'] === 'Pending'): ?>
                    <span class="badge" style="background-color: #f59e0b;">รอดำเนินการ</span>
                <?php elseif($req['status'] === 'In Progress'): ?>
                    <span class="badge" style="background-color: #3b82f6;">กำลังดำเนินการ</span>
                <?php elseif($req['status'] === 'Pending Approval'): ?>
                    <span class="badge" style="background-color: #f97316;">รอแอดมินอนุมัติ</span>
                <?php elseif($req['status'] === 'Completed'): ?>
                    <span class="badge" style="background-color: #10b981;">เสร็จสิ้น</span>
                <?php endif; ?>
            </td>
            
            <?php if($userRole === 'Admin'): ?>
            <td style="background: #f8fafc;">
                <?php if($req['status'] === 'Completed'): ?>
                    <!-- ถ้าปิดงานแล้ว โชว์สถานะ -->
                    <span style="color: green; font-weight: bold; font-size: 13px;">✔ ปิดงานแล้ว</span>
                
                <?php elseif(!empty($req['assignedTo'])): ?>
                    <!-- 🌟 ถ้ามอบหมายงานไปแล้ว ให้แสดงชื่อคนรับงานและซ่อนฟอร์ม -->
                    <div style="font-size: 13px; color: #1e293b; margin-bottom: 4px;">
                        <span style="color: #64748b; font-size: 11px;">ผู้รับผิดชอบ:</span><br>
                        <b style="color: #4f46e5;">👤 <?php echo htmlspecialchars($req['staffName']); ?></b>
                    </div>
                    
                    <!-- ปุ่มสำหรับเปลี่ยนคนรับงาน (จะกดเพื่อเปิดฟอร์มแก้) -->
                    <button type="button" onclick="document.getElementById('assignForm_<?php echo $req['requestId']; ?>').style.display='flex'; this.style.display='none';" style="background: none; border: none; color: #94a3b8; text-decoration: underline; font-size: 10px; cursor: pointer; padding: 0;">เปลี่ยนคนรับงาน</button>
                    
                    <form id="assignForm_<?php echo $req['requestId']; ?>" action="service_request.php" method="POST" style="display:none; flex-direction:column; gap:5px; margin-top:5px;">
                        <input type="hidden" name="requestId" value="<?php echo $req['requestId']; ?>">
                        <select name="assignedTo" required style="margin:0; padding:6px; font-size:12px; border: 1px solid #cbd5e1; border-radius: 4px;">
                            <option value="">-- เลือกพนักงานใหม่ --</option>
                            <?php 
                                $isCleaningJob = (mb_strpos($req['category'], 'ความสะอาด', 0, 'UTF-8') !== false);
                                foreach($staffs as $staff): 
                                    $isCleaner = in_array($staff['role'], ['Cleaner', 'Housekeeper']);
                                    if ($isCleaningJob && !$isCleaner) continue; 
                                    if (!$isCleaningJob && $isCleaner) continue; 
                                    $roleLabel = $isCleaner ? 'แม่บ้าน' : 'ช่าง';
                                    $selected = ($staff['userId'] == $req['assignedTo']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo $staff['userId']; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($staff['fullName']); ?> (<?php echo $roleLabel; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="assign_job" style="padding:6px; background:#f59e0b; color:white; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">อัปเดตคนรับงาน</button>
                    </form>

                <?php else: ?>
                    <!-- 🌟 ถ้ายืนยันยังไม่มีคนรับงาน ให้โชว์ฟอร์มสำหรับจ่ายงานตามปกติ -->
                    <form action="service_request.php" method="POST" style="display:flex; flex-direction:column; gap:5px; margin:0;">
                        <input type="hidden" name="requestId" value="<?php echo $req['requestId']; ?>">
                        <select name="assignedTo" required style="margin:0; padding:6px; font-size:12px; border: 1px solid #cbd5e1; border-radius: 4px;">
                            <option value="">-- เลือกพนักงาน --</option>
                            <?php 
                                $isCleaningJob = (mb_strpos($req['category'], 'ความสะอาด', 0, 'UTF-8') !== false);
                                foreach($staffs as $staff): 
                                    $isCleaner = in_array($staff['role'], ['Cleaner', 'Housekeeper']);
                                    if ($isCleaningJob && !$isCleaner) continue; 
                                    if (!$isCleaningJob && $isCleaner) continue; 
                                    $roleLabel = $isCleaner ? 'แม่บ้าน' : 'ช่าง';
                            ?>
                                <option value="<?php echo $staff['userId']; ?>"><?php echo htmlspecialchars($staff['fullName']); ?> (<?php echo $roleLabel; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="assign_job" style="padding:6px; background:#4f46e5; color:white; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">จ่ายงาน</button>
                    </form>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="6" style="text-align:center; padding:20px; color:#64748b;">ยังไม่มีข้อมูลประวัติ</td></tr>
        <?php endif; ?>
    </table>
</div>

<script>
    // สคริปต์ซ่อนข้อความแจ้งเตือนอัตโนมัติใน 3 วินาที
    document.addEventListener("DOMContentLoaded", function() {
        const alerts = document.querySelectorAll(".alert-box");
        alerts.forEach(function(alert) {
            setTimeout(function() {
                alert.style.opacity = "0";
                setTimeout(function() { alert.remove(); }, 500);
            }, 3000);
        });
    });
</script>
</body>
</html>