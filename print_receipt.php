<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบสิทธิ์ (แอดมินดูได้หมด ลูกบ้านดูได้เฉพาะของตัวเอง)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id'])) {
    die("❌ ไม่พบรหัสใบเสร็จ");
}

$invoiceId = $_GET['id'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// ดึงข้อมูลใบเสร็จ
$query = "
    SELECT i.*, r.roomNumber, u.fullName as tenantName, u.phone, u.email 
    FROM Invoices i
    JOIN Room r ON i.roomId = r.roomId
    JOIN User u ON i.tenantId = u.userId
    WHERE i.invoiceId = ? AND i.status = 'Paid'
";

// ถ้าเป็นลูกบ้าน ให้ล็อกดูได้แค่บิลของตัวเอง
if ($userRole === 'Tenant') {
    $query .= " AND i.tenantId = " . intval($userId);
}

$stmt = $conn->prepare($query);
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>❌ ไม่พบข้อมูลใบเสร็จ หรือบิลนี้ยังไม่ได้ชำระเงิน</h2><button onclick='window.close()' style='padding:10px 20px; cursor:pointer;'>ปิดหน้าต่างนี้</button></div>");
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>jasmin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- เพิ่มไลบรารีสำหรับดาวน์โหลด PDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    
    <style>
        /* ตั้งค่าฟอนต์เป็น Sarabun (ฟอนต์มาตรฐานเอกสารไทย) */
        body { font-family: 'Sarabun', sans-serif; background-color: #e2e8f0; color: #000; margin: 0; padding: 0; }
        
        /* จัดขนาดกระดาษ A4 */
        .a4-page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm;
            margin: 10mm auto;
            background: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        /* ซ่อนปุ่มต่างๆ เวลาสั่งปรินต์ (เผื่อมีคนกด Ctrl+P) */
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .a4-page { margin: 0; padding: 15mm; box-shadow: none; min-height: auto; width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body>

    <!-- แถบเมนูด้านบน (ซ่อนตอนแปลงเป็น PDF) -->
    <div class="no-print bg-slate-800 text-white p-4 flex flex-wrap justify-between items-center gap-3 sticky top-0 shadow-md z-50">
        <div class="flex items-center gap-2">
            <?php $receiptBack = ($userRole === 'Admin') ? 'manage_invoice.php' : 'view_invoices.php'; ?>
            <a href="<?php echo $receiptBack; ?>" onclick="if(window.history.length > 1){ window.history.back(); return false; }" class="flex items-center gap-1.5 bg-slate-700 hover:bg-slate-600 px-3.5 py-2 rounded-lg text-sm font-bold shadow-sm transition">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> ย้อนกลับ
            </a>
            <button onclick="window.close()" class="flex items-center gap-1.5 bg-slate-700 hover:bg-rose-600 px-3 py-2 rounded-lg text-sm font-bold shadow-sm transition">
                <i data-lucide="x" class="w-4 h-4"></i> ปิด
            </button>
        </div>
        
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="flex items-center gap-1.5 bg-slate-700 hover:bg-slate-600 px-3.5 py-2 rounded-lg text-sm font-bold shadow-sm transition">
                <i data-lucide="printer" class="w-4 h-4"></i> สั่งพิมพ์
            </button>
            <button onclick="downloadPDF()" class="flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-500 px-4 py-2 rounded-lg text-sm font-bold shadow-sm transition">
                <i data-lucide="download" class="w-4 h-4"></i> ดาวน์โหลดเป็น PDF
            </button>
        </div>
    </div>

    <!-- กระดาษ A4 (ส่วนที่จะถูกแคปไปทำ PDF) -->
    <div class="a4-page relative" id="receipt-content">
        
        <!-- Header -->
        <div class="flex justify-between items-start border-b-2 border-slate-800 pb-6 mb-6">
            <div>
                <!-- 🌟 เปลี่ยนชื่อเป็น หอพักจัสมิน -->
                <h1 class="text-3xl font-bold text-slate-800 tracking-tight">หอพักจัสมิน</h1>
                <p class="text-sm text-slate-600 mt-1">123 ถ.สุขุมวิท แขวงคลองเตย เขตคลองเตย กรุงเทพฯ 10110</p>
                <p class="text-sm text-slate-600">โทร: 02-XXX-XXXX | อีเมล: contact@jasmine-dorm.com</p>
            </div>
            <div class="text-right">
                <h2 class="text-2xl font-bold text-blue-800 mb-1">ใบเสร็จรับเงิน</h2>
                <h2 class="text-lg font-bold text-slate-500 mb-2">RECEIPT</h2>
                <p class="text-sm text-slate-800"><b>เลขที่ใบเสร็จ:</b> REC-<?php echo str_pad($invoice['invoiceId'], 5, '0', STR_PAD_LEFT); ?></p>
                <p class="text-sm text-slate-800"><b>วันที่ออกเอกสาร:</b> <?php echo date('d/m/Y'); ?></p>
            </div>
        </div>

        <!-- ข้อมูลลูกค้า -->
        <div class="flex justify-between mb-8 text-sm">
            <div class="w-1/2">
                <p class="font-bold text-slate-800 mb-2 bg-slate-100 p-1.5 rounded w-fit">ได้รับเงินจาก (Customer):</p>
                <p><b>ชื่อ-นามสกุล:</b> <?php echo htmlspecialchars($invoice['tenantName']); ?></p>
                <p><b>ห้องพัก:</b> ห้อง <?php echo htmlspecialchars($invoice['roomNumber']); ?></p>
                <p><b>เบอร์โทรศัพท์:</b> <?php echo htmlspecialchars($invoice['phone']); ?></p>
            </div>
            <div class="w-1/3 border border-slate-200 p-3 rounded-lg bg-slate-50 text-right">
                <p class="text-xs text-slate-500 mb-1">ชำระค่าเช่าประจำเดือน</p>
                <p class="text-lg font-bold text-slate-800"><?php echo htmlspecialchars($invoice['billingMonth']); ?></p>
                <div class="mt-2 inline-flex items-center gap-1 bg-emerald-100 text-emerald-700 px-2 py-1 rounded text-xs font-bold border border-emerald-200">
                    <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i> ชำระเงินเรียบร้อย
                </div>
            </div>
        </div>

        <!-- ตารางรายการ -->
        <table class="w-full text-sm mb-6 border-collapse">
            <thead>
                <tr class="bg-slate-800 text-white">
                    <th class="p-3 text-center border border-slate-800 w-12">ลำดับ</th>
                    <th class="p-3 text-left border border-slate-800">รายการ (Description)</th>
                    <th class="p-3 text-right border border-slate-800 w-32">จำนวนเงิน (บาท)</th>
                </tr>
            </thead>
            <tbody>
                <!-- ค่าห้อง -->
                <tr>
                    <td class="p-3 text-center border border-slate-300">1</td>
                    <td class="p-3 border border-slate-300">ค่าเช่าห้องพัก (Room Rent)</td>
                    <td class="p-3 text-right border border-slate-300"><?php echo number_format($invoice['rentPrice'], 2); ?></td>
                </tr>
                <!-- ค่าน้ำ -->
                <tr>
                    <td class="p-3 text-center border border-slate-300">2</td>
                    <td class="p-3 border border-slate-300">
                        ค่าน้ำประปา (Water) <br>
                        <span class="text-xs text-slate-500">มิเตอร์: <?php echo $invoice['waterPrev']; ?> - <?php echo $invoice['waterCurr']; ?> (<?php echo max(0, $invoice['waterCurr'] - $invoice['waterPrev']); ?> หน่วย @ <?php echo $invoice['waterUnitRate']; ?> บ.)</span>
                    </td>
                    <td class="p-3 text-right border border-slate-300">
                        <?php 
                            $waterTotal = max(0, $invoice['waterCurr'] - $invoice['waterPrev']) * $invoice['waterUnitRate'];
                            echo number_format($waterTotal, 2); 
                        ?>
                    </td>
                </tr>
                <!-- ค่าไฟ -->
                <tr>
                    <td class="p-3 text-center border border-slate-300">3</td>
                    <td class="p-3 border border-slate-300">
                        ค่าไฟฟ้า (Electricity) <br>
                        <span class="text-xs text-slate-500">มิเตอร์: <?php echo $invoice['electricPrev']; ?> - <?php echo $invoice['electricCurr']; ?> (<?php echo max(0, $invoice['electricCurr'] - $invoice['electricPrev']); ?> หน่วย @ <?php echo $invoice['electricUnitRate']; ?> บ.)</span>
                    </td>
                    <td class="p-3 text-right border border-slate-300">
                        <?php 
                            $electricTotal = max(0, $invoice['electricCurr'] - $invoice['electricPrev']) * $invoice['electricUnitRate'];
                            echo number_format($electricTotal, 2); 
                        ?>
                    </td>
                </tr>
                <!-- ค่าอื่นๆ / ค่าบริการส่วนกลาง -->
                <?php if($invoice['otherFee'] > 0): 
                    $otherFeeItems = [];
                    if (!empty($invoice['otherFeeNote'])) {
                        $parts = explode(',', $invoice['otherFeeNote']);
                        foreach($parts as $part) {
                            $part = trim($part);
                            if (!empty($part)) $otherFeeItems[] = $part;
                        }
                    }
                    if (empty($otherFeeItems)) {
                        if (abs($invoice['otherFee'] - 20.00) < 0.01) {
                            $otherFeeItems = ['ค่าขยะ (20.00 บาท)'];
                        } elseif (abs($invoice['otherFee'] - 100.00) < 0.01) {
                            $otherFeeItems = ['ค่าบริการส่วนกลาง (100.00 บาท)'];
                        } elseif (abs($invoice['otherFee'] - 120.00) < 0.01) {
                            $otherFeeItems = ['ค่าบริการส่วนกลาง (100.00 บาท)', 'ค่าขยะ (20.00 บาท)'];
                        } elseif (abs($invoice['otherFee'] - 150.00) < 0.01) {
                            $otherFeeItems = ['ค่าอินเทอร์เน็ต / Wi-Fi (150.00 บาท)'];
                        } else {
                            $otherFeeItems = ['ค่าบริการส่วนกลาง / อื่น ๆ (' . number_format($invoice['otherFee'], 2) . ' บาท)'];
                        }
                    }
                ?>
                <tr>
                    <td class="p-3 text-center border border-slate-300 align-top">4</td>
                    <td class="p-3 border border-slate-300">
                        <div class="font-bold text-slate-800">ค่าบริการส่วนกลาง / อื่นๆ (Other Fees)</div>
                        <div class="text-xs text-slate-600 mt-1 space-y-0.5">
                            <?php foreach($otherFeeItems as $feeItem): ?>
                                <div class="flex items-center gap-1.5 text-slate-600">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                    <span><?php echo htmlspecialchars($feeItem); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td class="p-3 text-right border border-slate-300 align-top font-mono">
                        <?php echo number_format($invoice['otherFee'], 2); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="bg-slate-50">
                    <td colspan="2" class="p-3 text-right font-bold border border-slate-300">ยอดรวมทั้งสิ้น (Total Amount)</td>
                    <td class="p-3 text-right font-bold text-lg border border-slate-300 border-b-4 border-b-slate-800 underline decoration-double">
                        <?php echo number_format($invoice['totalAmount'], 2); ?>
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- ลายเซ็นต์รับเงิน -->
        <div class="mt-16 flex justify-between px-10">
            <div class="text-center">
                <div class="border-b border-dashed border-slate-400 w-48 mb-2 mx-auto"></div>
                <p class="text-sm">( <?php echo htmlspecialchars($invoice['tenantName']); ?> )</p>
                <p class="text-xs text-slate-500 mt-1">ผู้ชำระเงิน (Payer)</p>
            </div>
            <div class="text-center">
                <div class="border-b border-dashed border-slate-400 w-48 mb-2 mx-auto h-6 flex items-end justify-center text-blue-800 text-xs italic">รับเงินผ่านระบบออนไลน์</div>
                <p class="text-sm">( นิติบุคคล / ผู้จัดการหอพัก )</p>
                <p class="text-xs text-slate-500 mt-1">ผู้รับเงิน (Receiver)</p>
            </div>
        </div>

        <div class="absolute bottom-10 left-0 w-full text-center no-print text-xs text-slate-300">
            - สิ้นสุดเอกสาร -
        </div>
    </div>

    <script>
        lucide.createIcons();

        // 🌟 ฟังก์ชันดาวน์โหลด PDF
        function downloadPDF() {
            const element = document.getElementById('receipt-content');
            
            // ตั้งค่าไฟล์ PDF
            const opt = {
                margin:       [10, 0, 10, 0], // เว้นขอบบนและล่างเล็กน้อย
                filename:     'Receipt_REC-<?php echo str_pad($invoice['invoiceId'], 5, '0', STR_PAD_LEFT); ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            // สร้างและดาวน์โหลด
            html2pdf().set(opt).from(element).save();
        }
    </script>
</body>
</html>