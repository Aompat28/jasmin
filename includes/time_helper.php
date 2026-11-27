<?php
/**
 * Time and Date Helper for Property Management System
 * Timezone: Asia/Bangkok (UTC+7)
 */

if (!function_exists('thai_datetime')) {
    function thai_datetime($datetime, $with_seconds = false) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '-';
        $time = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if (!$time) return '-';
        $format = $with_seconds ? "d/m/Y H:i:s" : "d/m/Y H:i";
        return date($format, $time);
    }
}

if (!function_exists('thai_date')) {
    function thai_date($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00') return '-';
        $time = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if (!$time) return '-';
        return date("d/m/Y", $time);
    }
}

if (!function_exists('time_ago_th')) {
    function time_ago_th($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '';
        $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if (!$timestamp) return '';
        $diff = time() - $timestamp;
        
        if ($diff < 0) return "เร็วๆ นี้";
        if ($diff < 15) return "เมื่อสักครู่";
        if ($diff < 60) return $diff . " วินาทีที่แล้ว";
        if ($diff < 3600) return floor($diff / 60) . " นาทีที่แล้ว";
        if ($diff < 86400) return floor($diff / 3600) . " ชั่วโมงที่แล้ว";
        if ($diff < 172800) return "เมื่อวานนี้ " . date("H:i", $timestamp) . " น.";
        if ($diff < 604800) return floor($diff / 86400) . " วันที่แล้ว";
        if ($diff < 2592000) return floor($diff / 86400) . " วันก่อน";
        return floor($diff / 2592000) . " เดือนก่อน";
    }
}

if (!function_exists('duration_th')) {
    function duration_th($startDatetime, $endDatetime) {
        if (empty($startDatetime) || empty($endDatetime)) return '';
        $start = is_numeric($startDatetime) ? (int)$startDatetime : strtotime($startDatetime);
        $end = is_numeric($endDatetime) ? (int)$endDatetime : strtotime($endDatetime);
        if (!$start || !$end) return '';
        $diff = max(0, $end - $start);
        
        $days = floor($diff / 86400);
        $hours = floor(($diff % 86400) / 3600);
        $minutes = floor(($diff % 3600) / 60);
        $seconds = $diff % 60;
        
        if ($days > 0) return "{$days} วัน {$hours} ชม.";
        if ($hours > 0) return "{$hours} ชม. {$minutes} นาที";
        if ($minutes > 0) return "{$minutes} นาที";
        return "{$seconds} วินาที";
    }
}

if (!function_exists('toThaiBillingMonth')) {
    function toThaiBillingMonth($rawMonth) {
        if (empty($rawMonth)) return '';
        $rawMonth = trim($rawMonth);
        if (preg_match('/^(\d{4})-(\d{2})$/', $rawMonth, $m)) {
            $thaiMonths = [
                '01' => 'มกราคม', '02' => 'กุมภาพันธ์', '03' => 'มีนาคม', '04' => 'เมษายน',
                '05' => 'พฤษภาคม', '06' => 'มิถุนายน', '07' => 'กรกฎาคม', '08' => 'สิงหาคม',
                '09' => 'กันยายน', '10' => 'ตุลาคม', '11' => 'พฤศจิกายน', '12' => 'ธันวาคม'
            ];
            return ($thaiMonths[$m[2]] ?? '') . ' ' . $m[1];
        }
        return $rawMonth;
    }
}

if (!function_exists('parseThaiBillingMonthToYm')) {
    function parseThaiBillingMonthToYm($thaiMonthStr) {
        if (empty($thaiMonthStr)) return '';
        $thaiMonthStr = trim($thaiMonthStr);
        $monthMap = [
            'มกราคม' => '01', 'กุมภาพันธ์' => '02', 'มีนาคม' => '03', 'เมษายน' => '04',
            'พฤษภาคม' => '05', 'มิถุนายน' => '06', 'กรกฎาคม' => '07', 'สิงหาคม' => '08',
            'กันยายน' => '09', 'ตุลาคม' => '10', 'พฤศจิกายน' => '11', 'ธันวาคม' => '12'
        ];
        foreach ($monthMap as $th => $mm) {
            if (strpos($thaiMonthStr, $th) !== false) {
                if (preg_match('/\b(20\d{2})\b/', $thaiMonthStr, $ym)) {
                    return $ym[1] . '-' . $mm;
                }
            }
        }
        return '';
    }
}


