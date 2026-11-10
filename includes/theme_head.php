<?php
// includes/theme_head.php
// ระบบจัดการธีมส่วนกลางสำหรับ 4 Role (Admin, Tenant, Technician, Cleaner)
// มีเพียง 2 ธีมเท่านั้น: Dark Theme (มืด) และ Light Theme (สว่าง)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!-- 🛡️ ป้องกันการส่งแบบฟอร์มซ้ำเมื่อกด Refresh (F5) สำหรับทุกหน้าในระบบ -->
<script>
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>
<?php
// ระบบธีมจะทำงานสำหรับผู้ใช้ที่เข้าสู่ระบบใน 4 Role (Admin, Tenant, Technician, Cleaner)
if (!isset($_SESSION['user_id'])) {
    return;
}

$themeRole = $_SESSION['role'] ?? 'Tenant';
$cookieRoleKey = 'jasmin_theme_' . $themeRole;
$serverTheme = $_COOKIE[$cookieRoleKey] ?? ($_COOKIE['jasmin_theme'] ?? null);

// ค่าเริ่มต้น: Admin เริ่มต้นที่ dark หรือตามที่เคยเลือก, Role อื่นเริ่มต้นที่ light หรือตามที่เคยเลือก
if (!$serverTheme || !in_array($serverTheme, ['dark', 'light'])) {
    $serverTheme = ($themeRole === 'Admin') ? 'dark' : 'light';
}
?>
<!-- 🌟 Jasmin Theme System (Dark vs Light) 🌟 -->
<script>
(function() {
  const currentRole = '<?php echo htmlspecialchars($themeRole); ?>';
  let activeTheme = localStorage.getItem('jasmin_theme_' + currentRole) || localStorage.getItem('jasmin_theme') || '<?php echo $serverTheme; ?>';
  if (activeTheme !== 'dark' && activeTheme !== 'light') {
    activeTheme = (currentRole === 'Admin') ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-theme', activeTheme);
  document.documentElement.classList.remove('theme-dark', 'theme-light');
  document.documentElement.classList.add('theme-' + activeTheme);
  // ตั้งค่า cookie ให้ตรงกับ localStorage เสมอ
  document.cookie = 'jasmin_theme_' + currentRole + '=' + activeTheme + '; path=/; max-age=31536000; SameSite=Lax';
  document.cookie = 'jasmin_theme=' + activeTheme + '; path=/; max-age=31536000; SameSite=Lax';
})();
</script>

<style>
  /* === การเปลี่ยนผ่านสีนุ่มนวล === */
  html, body {
    transition: background-color 0.2s ease, color 0.2s ease;
  }

  /* === 1. ธีมมืด (DARK THEME) - Midnight Slate Radial Gradient ตามรูปตัวอย่าง === */
  html[data-theme="dark"],
  html.theme-dark,
  body.theme-dark,
  html[data-theme="dark"] body {
    background: radial-gradient(circle at 15% 15%, #0f172a 0%, #080d1a 50%, #030712 100%) !important;
    background-color: #080d1a !important;
    background-attachment: fixed !important;
    color: #f1f5f9 !important;
  }

  /* === 2. ธีมสว่าง (LIGHT THEME) - Soft Warm Linen (#FAF9F6) ตามรูปตัวอย่าง === */
  html[data-theme="light"],
  html.theme-light,
  body.theme-light,
  html[data-theme="light"] body {
    background: #FAF9F6 !important;
    background-color: #FAF9F6 !important;
    background-image: none !important;
    color: #1e293b !important;
  }

  /* ช่องกรอกข้อมูล (Inputs, Textarea, Select) ให้เป็นสีขาวเหมือนหน้าอื่นเสมอ */
  input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="range"]):not([type="color"]),
  textarea,
  select {
    background-color: #ffffff !important;
    color: #1e293b !important;
  }
  input::placeholder,
  textarea::placeholder {
    color: #94a3b8 !important;
  }

  /* สไตล์กล่องสวิตช์เลือกธีม (Toggle Pill) */
  .theme-toggle-pill {
    display: inline-flex;
    align-items: center;
    padding: 3px;
    border-radius: 9999px;
    background-color: #f1f5f9;
    border: 1px solid #e2e8f0;
    gap: 3px;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
    user-select: none;
    vertical-align: middle;
  }
  html[data-theme="dark"] .theme-toggle-pill {
    background-color: #1e293b;
    border-color: #334155;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.4);
  }
  .theme-toggle-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
    transition: all 0.2s ease;
    cursor: pointer;
    border: none;
    outline: none;
    color: #64748b;
    background: transparent;
  }
  html[data-theme="dark"] .theme-toggle-btn {
    color: #94a3b8;
  }
  .theme-toggle-btn:hover {
    color: #0f172a;
  }
  html[data-theme="dark"] .theme-toggle-btn:hover {
    color: #f8fafc;
  }
  .theme-toggle-btn.active {
    background-color: #ffffff;
    color: #0f172a;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
  }
  html[data-theme="dark"] .theme-toggle-btn.active {
    background-color: #0f172a;
    color: #f8fafc;
    box-shadow: 0 2px 5px rgba(0,0,0,0.4);
  }
</style>

<script>
function setAppTheme(theme) {
  if (theme !== 'dark' && theme !== 'light') return;
  const currentRole = '<?php echo htmlspecialchars($themeRole); ?>';
  
  // 1. บันทึกลง localStorage สำหรับ Role นั้นๆ และบันทึก global สำรอง
  localStorage.setItem('jasmin_theme_' + currentRole, theme);
  localStorage.setItem('jasmin_theme', theme);
  
  // 2. บันทึกลง Cookie เพื่อให้ฝั่ง Server อ่านได้ในหน้าถัดไปทันที
  document.cookie = 'jasmin_theme_' + currentRole + '=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
  document.cookie = 'jasmin_theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
  
  // 3. ปรับ DOM ทันที ไม่ต้องรีเฟรชหน้า
  document.documentElement.setAttribute('data-theme', theme);
  document.documentElement.classList.remove('theme-dark', 'theme-light');
  document.documentElement.classList.add('theme-' + theme);
  
  if (document.body) {
    document.body.classList.remove('theme-dark', 'theme-light');
    document.body.classList.add('theme-' + theme);
  }
  
  // 4. อัปเดตปุ่มทุกจุดบนหน้าจอ
  updateThemeToggleButtons(theme);
}

function updateThemeToggleButtons(theme) {
  document.querySelectorAll('.theme-btn-dark').forEach(btn => {
    if (theme === 'dark') {
      btn.classList.add('active');
    } else {
      btn.classList.remove('active');
    }
  });
  document.querySelectorAll('.theme-btn-light').forEach(btn => {
    if (theme === 'light') {
      btn.classList.add('active');
    } else {
      btn.classList.remove('active');
    }
  });
}

// เมื่อโหลดหน้าเว็บเสร็จ ให้ซิงก์สถานะปุ่มและ body
document.addEventListener('DOMContentLoaded', function() {
  const currentRole = '<?php echo htmlspecialchars($themeRole); ?>';
  let theme = localStorage.getItem('jasmin_theme_' + currentRole) || localStorage.getItem('jasmin_theme') || '<?php echo $serverTheme; ?>';
  if (theme !== 'dark' && theme !== 'light') {
    theme = (currentRole === 'Admin') ? 'dark' : 'light';
  }
  
  document.documentElement.setAttribute('data-theme', theme);
  document.documentElement.classList.remove('theme-dark', 'theme-light');
  document.documentElement.classList.add('theme-' + theme);
  
  if (document.body) {
    document.body.classList.remove('theme-dark', 'theme-light');
    document.body.classList.add('theme-' + theme);
  }
  
  updateThemeToggleButtons(theme);
  
  if (window.lucide) {
    lucide.createIcons();
  }
});
</script>
