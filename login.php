<?php



session_start();



// เปิดระบบแสดง Error หน้าจอ จะได้รู้ว่าพังตรงไหน



ini_set('display_errors', 1);



ini_set('display_startup_errors', 1);



error_reporting(E_ALL);



require_once 'includes/db_connect.php';



// ถ้าเข้าระบบอยู่แล้ว ให้เด้งไปหน้า Dashboard ตามสิทธิ์



if (isset($_SESSION['user_id'])) {



    if ($_SESSION['role'] === 'Admin') {



        header("Location: admin_dashboard.php");



    } elseif ($_SESSION['role'] === 'Tenant') {



        header("Location: tenant_dashboard.php");



    } else {



        // ถ้าเป็นช่างซ่อม (Technician) หรือแม่บ้าน (Cleaner)



        header("Location: staff_dashboard.php");



    }



    exit();



}



$error = '';



if ($_SERVER['REQUEST_METHOD'] == 'POST') {



    $username = trim($_POST['username'] ?? '');



    $password = trim($_POST['password'] ?? '');



    try {



        $stmt = $conn->prepare("SELECT * FROM User WHERE username = ?");



        $stmt->execute([$username]);



        $user = $stmt->fetch(PDO::FETCH_ASSOC);



        if (!$user) {



            $error = "ไม่พบชื่อผู้ใช้งานนี้ในระบบ! กรุณาตรวจสอบชื่อผู้ใช้งานอีกครั้ง";



        } elseif (!empty($user['status']) && $user['status'] === 'Inactive') {



            $error = "บัญชีผู้ใช้นี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ!";



        } elseif (!(password_verify($password, $user['password']) || $password === $user['password'])) {



            $error = "รหัสผ่านไม่ถูกต้อง! กรุณาตรวจสอบรหัสผ่านอีกครั้ง";



        } else {



            $_SESSION['user_id'] = $user['userId'];



            $_SESSION['role'] = $user['role'];



            $_SESSION['full_name'] = $user['fullName'];



            // แยกทางไปตาม Role



            if ($user['role'] === 'Admin') {



                header("Location: admin_dashboard.php");



            } elseif ($user['role'] === 'Tenant') {



                header("Location: tenant_dashboard.php");



            } else {



                header("Location: staff_dashboard.php");



            }



            exit();



        }



    } catch (PDOException $e) {



        $error = "ข้อผิดพลาดฐานข้อมูล: " . $e->getMessage();



    }



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



      theme: {



        extend: {



          fontFamily: {



            sans: ['Prompt', 'sans-serif'],



            display: ['Kanit', 'sans-serif']



          },



          boxShadow: {



            'luxury': '0 25px 60px -15px rgba(15, 23, 42, 0.25), 0 0 40px rgba(59, 130, 246, 0.15)',



            'glow-blue': '0 0 35px -5px rgba(59, 130, 246, 0.4)'



          }



        }



      }



    }



  </script>



  <style>



    body {



      background: radial-gradient(circle at top left, #0f172a 0%, #090d16 100%);



      font-family: 'Prompt', sans-serif;



      overflow-x: hidden;



    }



    /* 🌟 Surprise Popup Entrance Animation (เหมือนเซอร์ไพร์ส) 🌟 */



    @keyframes surprisePopup {



      0% {



        opacity: 0;



        transform: scale(0.65) translateY(50px) rotate(-1deg);



      }



      55% {



        opacity: 1;



        transform: scale(1.03) translateY(-8px) rotate(0.5deg);



      }



      75% {



        transform: scale(0.985) translateY(3px) rotate(-0.2deg);



      }



      100% {



        opacity: 1;



        transform: scale(1) translateY(0) rotate(0deg);



      }



    }



    .animate-surprise {



      animation: surprisePopup 0.85s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;



    }



    /* Floating ambient orbs */



    @keyframes floatOrb1 {



      0%, 100% { transform: translate(0px, 0px) scale(1); }



      50% { transform: translate(30px, -20px) scale(1.1); }



    }



    @keyframes floatOrb2 {



      0%, 100% { transform: translate(0px, 0px) scale(1); }



      50% { transform: translate(-25px, 25px) scale(1.15); }



    }



    .animate-orb1 { animation: floatOrb1 12s ease-in-out infinite; }



    .animate-orb2 { animation: floatOrb2 15s ease-in-out infinite; }



    /* Custom subtle glass pattern */



    .bg-grid-pattern {



      background-image: radial-gradient(rgba(255, 255, 255, 0.08) 1px, transparent 1px);



      background-size: 28px 28px;



    }



  </style>



  <?php include_once 'includes/theme_head.php'; ?>
</head>



<body class="min-h-screen flex items-center justify-center p-3 sm:p-6 md:p-8 relative selection:bg-rose-500 selection:text-white">



  <!-- 🌌 Ambient Luxury Glow Effects 🌌 -->



  <div class="fixed inset-0 pointer-events-none overflow-hidden z-0">



    <div class="absolute -top-32 -left-32 w-96 h-96 bg-rose-400/25 rounded-full blur-[120px] animate-orb1"></div>



    <div class="absolute -bottom-32 -right-32 w-96 h-96 bg-pink-400/25 rounded-full blur-[140px] animate-orb2"></div>



    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-rose-300/15 rounded-full blur-[160px] pointer-events-none"></div>



    <div class="absolute inset-0 bg-grid-pattern opacity-40"></div>



  </div>



  <!-- 🎁 MODAL CARD (เด้งเซอร์ไพร์สแบบเรียบหรู) 🎁 -->



  <div class="animate-surprise relative z-10 max-w-4xl w-full bg-white/95 backdrop-blur-2xl rounded-[2.5rem] shadow-luxury border border-white/80 overflow-hidden flex flex-col md:flex-row my-auto">



    <!-- ========================================== -->



    <!-- ฝั่งซ้าย: ยินดีต้อนรับเข้าสู่ระบบหอพักจัสมิน (เรียบหรู คลีน มินิมอล) -->



    <!-- ========================================== -->



    <div class="md:w-5/12 bg-gradient-to-br from-stone-950 via-stone-900 to-rose-950 text-white p-8 sm:p-12 flex flex-col justify-between relative overflow-hidden">



        <!-- Ambient Decorative Glow inside Left Card -->



        <div class="absolute -right-12 -bottom-12 w-64 h-64 bg-rose-500/20 rounded-full blur-3xl pointer-events-none"></div>



        <div class="absolute -left-10 -top-10 w-48 h-48 bg-pink-500/20 rounded-full blur-2xl pointer-events-none"></div>



        <!-- Content: ยินดีต้อนรับเข้าสู่ระบบหอพักจัสมิน -->



        <div class="relative z-10 my-auto flex flex-col justify-center py-6 sm:py-10">



            <!-- Icon -->



            <div class="w-16 h-16 rounded-3xl bg-gradient-to-br from-rose-500 to-pink-600 p-0.5 shadow-glow-blue flex items-center justify-center mb-6">



                <div class="w-full h-full bg-slate-950/70 backdrop-blur-sm rounded-[22px] flex items-center justify-center">



                    <i data-lucide="building-2" class="w-8 h-8 text-rose-400"></i>



                </div>



            </div>



            <!-- Badge -->



            <div class="mb-4">



                <span class="px-3.5 py-1.5 rounded-full bg-rose-500/15 border border-rose-400/30 text-rose-300 text-xs font-mono font-bold tracking-wider uppercase inline-flex items-center gap-1.5">



                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span>



                    Jasmine Residence



                </span>



            </div>



            <!-- Headline: ยินดีต้อนรับเข้าสู่ระบบหอพักจัสมิน -->



            <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-white leading-tight tracking-tight">



                ยินดีต้อนรับ<br>



                เข้าสู่ระบบ<br>



                <span class="text-transparent bg-clip-text bg-gradient-to-r from-rose-400 via-pink-300 to-rose-300">หอพักจัสมิน</span>



            </h1>



        </div>



        <div class="relative z-10 pt-6 border-t border-white/10 flex items-center justify-between text-[11px] text-slate-400">



            <span class="flex items-center gap-1.5"><i data-lucide="shield-check" class="w-3.5 h-3.5 text-rose-400"></i> Smart Residence</span>



            <span class="font-mono text-[10px] text-slate-500">&copy; 2026 Jasmine</span>



        </div>



    </div>



    <div class="md:w-7/12 p-8 sm:p-12 md:p-14 flex flex-col justify-center bg-white/80">



        <div class="mb-8">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-rose-50 border border-rose-200/80 text-rose-700 text-xs font-bold mb-3 shadow-sm">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-rose-600"></span>
                </span>
                เข้าสู่ระบบสมาชิก
            </div>
            <h2 class="text-2xl sm:text-3xl font-display font-extrabold text-slate-900 tracking-tight">เข้าสู่ระบบ</h2>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">กรุณากรอกชื่อผู้ใช้งานและรหัสผ่านเพื่อเข้าสู่ระบบ</p>
        </div>



        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'pwd_changed'): ?>



            <div id="loginSuccessAlert" class="mb-6 p-4 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-2xl text-xs sm:text-sm font-semibold flex items-center justify-between shadow-sm transition-all duration-500">



                <div class="flex items-center gap-2.5">



                    <div class="w-7 h-7 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">



                        <i data-lucide="check-circle" class="w-4 h-4"></i>



                    </div>



                    <span>ทำการเปลี่ยนรหัสผ่านแล้ว! กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่ของคุณ</span>



                </div>



                <button type="button" onclick="closeSuccessAlert()" class="text-emerald-500 hover:text-emerald-800 p-1 rounded-full transition cursor-pointer" title="ปิด">



                    <i data-lucide="x" class="w-4 h-4"></i>



                </button>



            </div>



            <script>



                function closeSuccessAlert() {



                    const alertEl = document.getElementById('loginSuccessAlert');



                    if (alertEl) {



                        alertEl.style.opacity = '0';



                        alertEl.style.transform = 'translateY(-10px)';



                        setTimeout(() => { alertEl.style.display = 'none'; }, 500);



                    }



                }



                setTimeout(closeSuccessAlert, 4000);



            </script>



        <?php endif; ?>



        <?php if ($error): ?>



            <div class="mb-6 p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-800 rounded-2xl text-xs sm:text-sm font-semibold flex items-start gap-2.5 shadow-sm">



                <div class="w-7 h-7 rounded-xl bg-rose-100 text-rose-700 flex items-center justify-center shrink-0 mt-0.5">



                    <i data-lucide="alert-circle" class="w-4 h-4"></i>



                </div>



                <div class="leading-relaxed"><?php echo htmlspecialchars($error); ?></div>



            </div>



        <?php endif; ?>



        <!-- Form: autocomplete off ป้องกันเบราว์เซอร์จำ root มาใส่ -->



        <form action="" method="POST" autocomplete="off" class="space-y-5">



            <!-- ช่อง Username: ไม่มีการใส่ value="root" เด็ดขาด และเคลียร์ค่าเป็นค่าว่าง -->



            <div>



                <label class="block text-xs sm:text-sm font-bold text-slate-700 mb-2">ชื่อผู้ใช้งาน (Username)</label>



                <div class="relative group">



                    <div class="w-10 h-10 absolute left-2.5 top-1/2 -translate-y-1/2 flex items-center justify-center text-slate-400 group-focus-within:text-rose-600 transition">



                        <i data-lucide="user" class="w-5 h-5"></i>



                    </div>



                    <input type="text"

                           name="username"

                           id="loginUsername"

                           required

                           autocomplete="off"

                           value=""

                           placeholder="กรอกชื่อผู้ใช้งานของคุณ"

                           class="w-full pl-12 pr-4 py-3.5 bg-slate-50/80 hover:bg-slate-50 border border-slate-200 rounded-2xl focus:bg-white focus:border-blue-600 focus:ring-4 focus:ring-blue-500/10 outline-none transition text-sm font-medium text-slate-800 placeholder-slate-400">



                </div>



            </div>



            <!-- ช่อง Password -->



            <div>



                <div class="flex items-center justify-between mb-2">



                    <label class="block text-xs sm:text-sm font-bold text-slate-700">รหัสผ่าน (Password)</label>



                </div>



                <div class="relative group">



                    <div class="w-10 h-10 absolute left-2.5 top-1/2 -translate-y-1/2 flex items-center justify-center text-slate-400 group-focus-within:text-rose-600 transition">



                        <i data-lucide="lock" class="w-5 h-5"></i>



                    </div>



                    <input type="password"

                           id="loginPassword"

                           name="password"

                           required

                           autocomplete="current-password"

                           value=""

                           placeholder="ลูกบ้านใช้เบอร์โทรศัพท์เป็นรหัสผ่าน"

                           class="w-full pl-12 pr-12 py-3.5 bg-slate-50/80 hover:bg-slate-50 border border-slate-200 rounded-2xl focus:bg-white focus:border-blue-600 focus:ring-4 focus:ring-blue-500/10 outline-none transition text-sm font-medium text-slate-800 placeholder-slate-400">



                    <button type="button"



                            onclick="togglePasswordVisibility('loginPassword', this)"



                            class="w-10 h-10 absolute right-2 top-1/2 -translate-y-1/2 flex items-center justify-center text-slate-400 hover:text-slate-600 transition cursor-pointer rounded-xl hover:bg-slate-100"



                            title="แสดง/ซ่อนรหัสผ่าน">



                        <i data-lucide="eye" class="w-4 h-4"></i>



                    </button>



                </div>



            </div>



            <!-- ปุ่มเข้าสู่ระบบ -->



            <button type="submit" class="w-full py-4 mt-2 bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-700 hover:from-blue-700 hover:to-indigo-700 text-white font-bold rounded-2xl shadow-lg shadow-rose-500/30 hover:shadow-blue-500/40 transition-all duration-300 flex justify-center items-center gap-2.5 cursor-pointer transform active:scale-[0.98] text-sm sm:text-base">



                <span>เข้าสู่ระบบ</span>



                <i data-lucide="arrow-right" class="w-4 h-4"></i>



            </button>



        </form>



    </div>



  </div>



  <script>
    lucide.createIcons();



    // บังคับเคลียร์ค่า root ทันทีที่โหลดหน้าจอ ป้องกัน Password Manager เติมค้าง



    window.addEventListener('DOMContentLoaded', function() {



        const userInput = document.getElementById('loginUsername');



        if (userInput) {



            userInput.value = '';



        }



        const passInput = document.getElementById('loginPassword');



        if (passInput) {



            passInput.value = '';



        }



    });



    function togglePasswordVisibility(inputId, btn) {



        const input = document.getElementById(inputId);



        if (!input) return;



        const isPassword = input.type === 'password';



        input.type = isPassword ? 'text' : 'password';



        btn.innerHTML = isPassword ? '<i data-lucide="eye-off" class="w-4 h-4"></i>' : '<i data-lucide="eye" class="w-4 h-4"></i>';



        if (window.lucide) { lucide.createIcons(); }



    }



  </script>



</body>



</html>



