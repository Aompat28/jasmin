/**
 * Real-time Time & Clock Helper
 * Automatically handles live clocks and relative time ('เมื่อสักครู่', 'X นาทีที่แล้ว')
 * Timezone: Asia/Bangkok
 */

(function() {
    // คำนวณความต่างเวลาระหว่าง Client กับ Server (ถ้ามีระบุใน body/meta)
    let serverTimeOffset = 0;
    const serverTimestampEl = document.querySelector('[data-server-epoch]');
    if (serverTimestampEl) {
        const serverEpoch = parseInt(serverTimestampEl.getAttribute('data-server-epoch'), 10) * 1000;
        if (!isNaN(serverEpoch)) {
            serverTimeOffset = serverEpoch - Date.now();
        }
    }

    function getNow() {
        return new Date(Date.now() + serverTimeOffset);
    }

    function padZero(num, size = 2) {
        return String(num).padStart(size, '0');
    }

    function formatThaiDateTime(date, withSeconds = true) {
        const d = padZero(date.getDate());
        const m = padZero(date.getMonth() + 1);
        const y = date.getFullYear();
        const hh = padZero(date.getHours());
        const mm = padZero(date.getMinutes());
        const ss = padZero(date.getSeconds());
        return withSeconds ? `${d}/${m}/${y} ${hh}:${mm}:${ss} น.` : `${d}/${m}/${y} ${hh}:${mm} น.`;
    }

    function formatThaiTimeOnly(date, withSeconds = true) {
        const hh = padZero(date.getHours());
        const mm = padZero(date.getMinutes());
        const ss = padZero(date.getSeconds());
        return withSeconds ? `${hh}:${mm}:${ss} น.` : `${hh}:${mm} น.`;
    }

    // แปลงสตริงวันที่เป็น Date object รองรับทั้ง 'YYYY-MM-DD HH:mm:ss' และ ISO
    function parseDate(dateStr) {
        if (!dateStr) return null;
        if (dateStr instanceof Date) return dateStr;
        // หากเป็น YYYY-MM-DD HH:mm:ss แทนช่องว่างด้วย T เพื่อให้เบราว์เซอร์ parse ได้สม่ำเสมอ
        const cleanStr = String(dateStr).trim().replace(' ', 'T');
        const parsed = new Date(cleanStr);
        if (isNaN(parsed.getTime())) {
            // Fallback manual regex match
            const m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
            if (m) {
                return new Date(m[1], m[2] - 1, m[3], m[4], m[5], m[6] || 0);
            }
            return null;
        }
        return parsed;
    }

    function formatTimeAgoTh(dateStr) {
        const d = parseDate(dateStr);
        if (!d) return '';
        const now = getNow();
        const diffMs = now.getTime() - d.getTime();
        const diffSec = Math.floor(diffMs / 1000);

        if (diffSec < 0) return 'เร็วๆ นี้';
        if (diffSec < 15) return 'เมื่อสักครู่';
        if (diffSec < 60) return `${diffSec} วินาทีที่แล้ว`;
        const diffMin = Math.floor(diffSec / 60);
        if (diffMin < 60) return `${diffMin} นาทีที่แล้ว`;
        const diffHours = Math.floor(diffMin / 60);
        if (diffHours < 24) return `${diffHours} ชั่วโมงที่แล้ว`;
        const diffDays = Math.floor(diffHours / 24);
        if (diffDays === 1) {
            return `เมื่อวานนี้ ${padZero(d.getHours())}:${padZero(d.getMinutes())} น.`;
        }
        if (diffDays < 7) {
            return `${diffDays} วันที่แล้ว`;
        }
        if (diffDays < 30) {
            return `${diffDays} วันก่อน`;
        }
        return `${Math.floor(diffDays / 30)} เดือนก่อน`;
    }

    function updateLiveClocks() {
        const now = getNow();
        const clockElements = document.querySelectorAll('.realtime-live-clock');
        clockElements.forEach(el => {
            const timeOnly = el.getAttribute('data-time-only') === 'true';
            const noSeconds = el.getAttribute('data-no-seconds') === 'true';
            if (timeOnly) {
                el.textContent = formatThaiTimeOnly(now, !noSeconds);
            } else {
                el.textContent = formatThaiDateTime(now, !noSeconds);
            }
        });
    }

    function updateAllRelativeTimes() {
        const agoElements = document.querySelectorAll('[data-realtime-ago]');
        agoElements.forEach(el => {
            const timeStr = el.getAttribute('data-realtime-ago');
            if (timeStr) {
                const prefix = el.getAttribute('data-prefix') || '';
                const suffix = el.getAttribute('data-suffix') || '';
                const formatted = formatTimeAgoTh(timeStr);
                if (formatted) {
                    el.textContent = `${prefix}${formatted}${suffix}`;
                }
            }
        });
    }

    // สตาร์ตการทำงาน
    function init() {
        updateLiveClocks();
        updateAllRelativeTimes();

        // นาฬิกาเดินสดทุกวินาที
        setInterval(updateLiveClocks, 1000);

        // เวลาเปรียบเทียบอัปเดตทุก 15 วินาที
        setInterval(updateAllRelativeTimes, 15000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ส่งออกเป็น Global ฟังก์ชันเผื่อเรียกใช้งานแบบ Programmatic
    window.RealtimeTime = {
        getNow,
        formatDateTime: formatThaiDateTime,
        formatTimeOnly: formatThaiTimeOnly,
        timeAgo: formatTimeAgoTh,
        update: () => {
            updateLiveClocks();
            updateAllRelativeTimes();
        }
    };
})();

