/**
 * Global SweetAlert2 Interceptor for Property Management System
 * Automatically replaces browser native alert() and confirm() with modern SweetAlert2 dialogues.
 */

if (typeof window.nativeAlert === 'undefined') {
    window.nativeAlert = window.alert;
    window.alert = function(msg) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'แจ้งเตือน',
                html: String(msg).replace(/\n/g, '<br>'),
                icon: 'info',
                confirmButtonColor: '#3b82f6',
                confirmButtonText: 'ตกลง',
                customClass: {
                    popup: 'rounded-3xl shadow-2xl font-sans border border-slate-100 p-6',
                    confirmButton: 'rounded-xl px-5 py-2.5 font-semibold text-sm shadow-md'
                }
            });
        } else {
            window.nativeAlert(msg);
        }
    };
}

document.addEventListener('DOMContentLoaded', function() {
    // 1. Auto-convert inline onclick="return confirm(...)" and onsubmit="return confirm(...)" to data-confirm
    document.querySelectorAll('a[onclick*="confirm"], button[onclick*="confirm"], form[onsubmit*="confirm"]').forEach(el => {
        let onclickVal = el.getAttribute('onclick') || '';
        let onsubmitVal = el.getAttribute('onsubmit') || '';

        if (onclickVal.includes('confirm(')) {
            let match = onclickVal.match(/confirm\s*\(\s*['"](.*?)['"]\s*\)/);
            if (match && match[1]) {
                el.setAttribute('data-confirm', match[1]);
            }
            el.removeAttribute('onclick');
        }
        if (onsubmitVal.includes('confirm(')) {
            let match = onsubmitVal.match(/confirm\s*\(\s*['"](.*?)['"]\s*\)/);
            if (match && match[1]) {
                el.setAttribute('data-confirm', match[1]);
            }
            el.removeAttribute('onsubmit');
        }
    });

    // 2. Click Event Interceptor (Links & Logout)
    document.body.addEventListener('click', function(e) {
        let target = e.target.closest('a[href*="logout.php"], a[data-confirm], button[data-confirm]');
        if (!target) return;

        let href = target.getAttribute('href');
        let dataConfirm = target.getAttribute('data-confirm');
        let isLogout = href && href.includes('logout.php');

        if (isLogout || dataConfirm) {
            e.preventDefault();
            e.stopPropagation();

            let title = isLogout ? 'ออกจากระบบ?' : 'ยืนยันการทำรายการ?';
            let text = dataConfirm || (isLogout ? 'คุณต้องการออกจากระบบหอพักจัสมิน ใช่หรือไม่?' : '');
            let isDelete = text.includes('ลบ') || text.includes('ยกเลิก') || isLogout;
            let confirmColor = isLogout ? '#e11d48' : (isDelete ? '#e11d48' : '#3b82f6');
            let iconType = isLogout ? 'question' : (isDelete ? 'warning' : 'question');
            let btnText = isLogout ? 'ใช่, ออกจากระบบ' : (isDelete ? 'ตกลง, ดำเนินการ' : 'ตกลง, ยืนยัน');

            Swal.fire({
                title: title,
                html: text,
                icon: iconType,
                showCancelButton: true,
                confirmButtonColor: confirmColor,
                cancelButtonColor: '#64748b',
                confirmButtonText: btnText,
                cancelButtonText: 'ยกเลิก',
                reverseButtons: true,
                customClass: {
                    popup: 'rounded-3xl shadow-2xl font-sans border border-slate-100',
                    confirmButton: 'rounded-xl px-5 py-2.5 font-semibold text-sm shadow-md',
                    cancelButton: 'rounded-xl px-5 py-2.5 font-semibold text-sm'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    if (isLogout || href) {
                        window.location.href = href;
                    } else {
                        let form = target.closest('form');
                        if (form) {
                            let submitName = target.getAttribute('name');
                            if (submitName && !form.querySelector('input[name="' + submitName + '"]')) {
                                let hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = submitName;
                                hidden.value = target.getAttribute('value') || '1';
                                form.appendChild(hidden);
                            }
                            form.setAttribute('data-swal-submitted', 'true');
                            form.submit();
                        }
                    }
                }
            });
            return false;
        }
    }, true);

    // 3. Form Submit Event Interceptor
    document.body.addEventListener('submit', function(e) {
        let form = e.target;
        if (form.getAttribute('data-swal-submitted') === 'true') {
            return true;
        }

        let dataConfirm = form.getAttribute('data-confirm');
        if (dataConfirm) {
            e.preventDefault();
            e.stopPropagation();

            let isDelete = dataConfirm.includes('ลบ') || dataConfirm.includes('ยกเลิก');
            let confirmColor = isDelete ? '#e11d48' : '#3b82f6';
            let iconType = isDelete ? 'warning' : 'question';
            let btnText = isDelete ? 'ตกลง, ดำเนินการ' : 'ตกลง, ยืนยัน';

            Swal.fire({
                title: 'ยืนยันการทำรายการ?',
                html: dataConfirm.replace(/\\n/g, '<br>'),
                icon: iconType,
                showCancelButton: true,
                confirmButtonColor: confirmColor,
                cancelButtonColor: '#64748b',
                confirmButtonText: btnText,
                cancelButtonText: 'ยกเลิก',
                reverseButtons: true,
                customClass: {
                    popup: 'rounded-3xl shadow-2xl font-sans border border-slate-100',
                    confirmButton: 'rounded-xl px-5 py-2.5 font-semibold text-sm shadow-md',
                    cancelButton: 'rounded-xl px-5 py-2.5 font-semibold text-sm'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    let submitter = e.submitter || form.querySelector('button[type="submit"][name], input[type="submit"][name]');
                    if (submitter) {
                        let name = submitter.getAttribute('name');
                        if (name && !form.querySelector('input[type="hidden"][name="' + name + '"]')) {
                            let hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = name;
                            hidden.value = submitter.getAttribute('value') || '1';
                            form.appendChild(hidden);
                        }
                    }
                    form.setAttribute('data-swal-submitted', 'true');
                    form.submit();
                }
            });
            return false;
        }
    }, true);

    // 4. Auto-dismiss top status alert banners after 5 seconds
    setTimeout(function() {
        let alertBoxes = document.querySelectorAll('.alert-box');
        alertBoxes.forEach(function(box) {
            if (!box.closest('form') && !box.id.includes('Notice') && !box.id.includes('Warning') && !box.id.includes('noRefund')) {
                box.style.transition = 'all 0.5s ease';
                box.style.opacity = '0';
                box.style.transform = 'translateY(-10px)';
                box.style.maxHeight = '0px';
                box.style.padding = '0px';
                box.style.margin = '0px';
                box.style.overflow = 'hidden';
                setTimeout(function() { box.remove(); }, 500);
            }
        });
    }, 5000);
});
