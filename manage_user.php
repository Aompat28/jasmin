<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบสิทธิ์ ต้องเป็น Admin เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$message = "";

// --- 1. ระบบเพิ่มผู้ใช้ใหม่ ---
if (isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $fullName = trim($_POST['fullName']);
    $phone = trim($_POST['phone']);
    $role = $_POST['role'];

    try {
        $stmt = $conn->prepare("INSERT INTO User (username, password, fullName, phone, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $password, $fullName, $phone, $role]);
        $message = "<div style='color: green; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px;'>✅ เพิ่มผู้ใช้งานใหม่สำเร็จ!</div>";
    } catch(PDOException $e) {
        $message = "<div style='color: red; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px;'>❌ เกิดข้อผิดพลาด: ชื่อผู้ใช้นี้ (Username) อาจมีคนใช้แล้ว</div>";
    }
}

// --- 2. ระบบลบผู้ใช้งาน ---
if (isset($_POST['delete_user'])) {
    $delUserId = $_POST['userId'];
    
    if ($delUserId == $_SESSION['user_id']) {
        $message = "<div style='color: red; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px;'>❌ ไม่สามารถลบบัญชีของตัวเองขณะล็อกอินได้ครับ</div>";
    } else {
        try {
            $stmt = $conn->prepare("DELETE FROM User WHERE userId = ?");
            $stmt->execute([$delUserId]);
            $message = "<div style='color: green; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px;'>🗑️ ลบผู้ใช้งานสำเร็จ!</div>";
        } catch(PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = "<div style='color: red; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px;'>❌ ไม่สามารถลบผู้ใช้คนนี้ได้ เนื่องจากผู้ใช้นี้ยังมี 'สัญญาเช่า' หรือ 'บิล' ผูกอยู่ในระบบ</div>";
            } else {
                $message = "<div style='color: red; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px;'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
            }
        }
    }
}

// ดึงข้อมูลผู้ใช้งานทั้งหมด
$users = $conn->query("SELECT * FROM User ORDER BY role ASC, fullName ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>jasmin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
      tailwind.config = { theme: { extend: { fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] } } } }
    </script>
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #FAF9F6; padding: 30px; color: #1e293b; }
        .container { max-width: 950px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .back-btn { text-decoration: none; color: #4f46e5; font-weight: bold; display: inline-block; margin-bottom: 20px; }
        
        .form-box { background: #f1f5f9; padding: 20px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #e2e8f0; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 150px; }
        input, select { padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; }
        button.btn-add { background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #e2e8f0; padding: 12px; text-align: left; font-size: 14px; }
        th { background: #f8fafc; }
        
        .btn-delete { background: #ef4444; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: bold; }
        .btn-delete:hover { background: #dc2626; }
        
        .role-badge { padding: 4px 8px; border-radius: 20px; font-size: 11px; font-weight: bold; color: white; }
    </style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased bg-[#FAF9F6] text-slate-800">

  <?php include_once 'includes/admin_sidebar.php'; ?>

<div class="container">
    <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 24px;">
        <a href="admin_dashboard.php" style="display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; background: white; border: 1px solid #cbd5e1; border-radius: 12px; color: #475569; text-decoration: none; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.06);" title="กลับหน้าหลักผู้ดูแลระบบ">
            <span style="font-size: 20px; font-weight: bold; line-height: 1;">←</span>
        </a>
        <div>
            <h2 style="margin: 0; font-size: 22px; color: #1e293b;">👥 ระบบจัดการผู้ใช้งาน (User Management)</h2>
            <p style="margin: 4px 0 0 0; font-size: 13px; color: #64748b;">เพิ่ม/ลบ และจัดการสิทธิ์ผู้ใช้งานในระบบ</p>
        </div>
    </div>
    
    <?php echo $message; ?>

    <form action="" method="POST" class="form-box">
        <div class="form-group">
            <label>ชื่อ-นามสกุล:</label>
            <input type="text" name="fullName" required>
        </div>
        <div class="form-group">
            <label>Username (สำหรับล็อกอิน):</label>
            <input type="text" name="username" required>
        </div>
        <div class="form-group">
            <label>รหัสผ่าน:</label>
            <input type="password" name="password" required>
        </div>
        <div class="form-group">
            <label>เบอร์โทร:</label>
            <input type="text" name="phone">
        </div>
        <div class="form-group">
            <label>สิทธิ์การใช้งาน (Role):</label>
            <select name="role" required>
                <option value="Tenant">ผู้เช่า (Tenant)</option>
                <option value="Admin">แอดมิน (Admin)</option>
                <!-- 🌟 แยกสิทธิ์แม่บ้านและช่างออกจากกัน -->
                <option value="Housekeeper">แม่บ้าน (Housekeeper)</option>
                <option value="Technician">ช่างซ่อม (Technician)</option>
            </select>
        </div>
        <button type="submit" name="add_user" class="btn-add">+ เพิ่มผู้ใช้</button>
    </form>

    <table>
        <tr>
            <th>ID</th>
            <th>ชื่อ-นามสกุล</th>
            <th>Username</th>
            <th>เบอร์โทร</th>
            <th>สิทธิ์ (Role)</th>
            <th>จัดการ</th>
        </tr>
        <?php foreach($users as $u): ?>
        <tr>
            <td><?php echo $u['userId']; ?></td>
            <td><b><?php echo htmlspecialchars($u['fullName']); ?></b></td>
            <td><?php echo htmlspecialchars($u['username']); ?></td>
            <td><?php echo htmlspecialchars($u['phone']); ?></td>
            <td>
                <!-- 🌟 แสดงป้ายสีแบ่งตามสิทธิ์ -->
                <?php if($u['role'] == 'Admin'): ?>
                    <span class="role-badge" style="background: #3b82f6;">Admin</span>
                <?php elseif($u['role'] == 'Tenant'): ?>
                    <span class="role-badge" style="background: #10b981;">Tenant</span>
                <?php elseif($u['role'] == 'Housekeeper'): ?>
                    <span class="role-badge" style="background: #8b5cf6;">Housekeeper</span>
                <?php elseif($u['role'] == 'Technician'): ?>
                    <span class="role-badge" style="background: #f59e0b;">Technician</span>
                <?php else: ?>
                    <span class="role-badge" style="background: #64748b;"><?php echo htmlspecialchars($u['role']); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if($u['userId'] != $_SESSION['user_id']): ?>
                    <form action="" method="POST" style="margin: 0;" onsubmit="return confirm('คุณแน่ใจหรือไม่ที่จะลบผู้ใช้ <?php echo $u['fullName']; ?>?');">
                        <input type="hidden" name="userId" value="<?php echo $u['userId']; ?>">
                        <button type="submit" name="delete_user" class="btn-delete">🗑️ ลบ</button>
                    </form>
                <?php else: ?>
                    <span style="font-size: 11px; color: #94a3b8; font-weight: bold;">👤 บัญชีของคุณ</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

</body>
</html>