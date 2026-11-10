<?php
session_start();
// รวมระบบอนุมัติคำขอต่ออายุสัญญาเช่าเข้ากับระบบจัดการห้องพักและสัญญาเช่า (manage_room.php?tab=renewals) เรียบร้อยแล้ว
header("Location: manage_room.php?tab=renewals");
exit();
