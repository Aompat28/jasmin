<?php
session_start();
// รวมระบบอนุมัติคำขอย้ายห้องพักเข้ากับหน้าระบบจัดการห้องพักและสัญญาเช่า (manage_room.php?tab=room_changes) เรียบร้อยแล้ว
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$redirectUrl = 'manage_room.php?tab=room_changes' . (!empty($queryString) ? '&' . $queryString : '');
header("Location: " . $redirectUrl);
exit();
