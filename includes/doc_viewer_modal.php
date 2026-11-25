<!-- Modal ดูเอกสารสัญญาภายในเว็บ (Image / PDF Viewer) -->
<div id="imageViewerModal" class="hidden fixed inset-0 z-[70] overflow-y-auto bg-slate-900/80 backdrop-blur-md flex items-center justify-center p-3 sm:p-5 transition-all duration-300" onclick="if(event.target === this) closeDocModal();">
  <div class="relative bg-white rounded-3xl max-w-4xl w-full p-4 sm:p-5 shadow-2xl border border-slate-100 flex flex-col max-h-[90vh] animate-in fade-in zoom-in-95 duration-200">
    
    <!-- Modal Header -->
    <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-3">
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center">
          <i data-lucide="file-text" class="w-4 h-4"></i>
        </div>
        <h3 id="docModalTitle" class="font-bold text-slate-800 text-sm sm:text-base">เอกสารแนบ</h3>
      </div>
      <div class="flex items-center gap-2">
        <a id="downloadDocBtn" href="#" download target="_blank" class="p-2 text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition flex items-center gap-1 text-xs font-bold" title="ดาวน์โหลดไฟล์">
          <i data-lucide="download" class="w-4 h-4"></i>
          <span class="hidden sm:inline">ดาวน์โหลด</span>
        </a>
        <button type="button" onclick="closeDocModal()" class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition cursor-pointer" title="ปิดหน้าต่าง">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
    </div>
    
    <!-- Modal Content Box -->
    <div class="flex-1 overflow-auto flex items-center justify-center min-h-[350px] max-h-[75vh] bg-slate-900/5 rounded-2xl p-2 border border-slate-200/60 relative">
      <img id="viewerImage" src="" alt="เอกสารสัญญา" class="max-w-full max-h-[70vh] object-contain rounded-xl shadow-md transition-all">
      <iframe id="viewerIframe" src="" class="w-full h-[70vh] rounded-xl hidden border-0" frameborder="0"></iframe>
    </div>

    <!-- Modal Footer -->
    <div class="pt-3 border-t border-slate-100 flex justify-end">
      <button type="button" onclick="closeDocModal()" class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-xl transition cursor-pointer shadow-sm">
        ปิดหน้าต่าง
      </button>
    </div>
  </div>
</div>

<script>
  function openDocModal(filePath, title) {
    if (!filePath || filePath.trim() === '') return;

    const modal = document.getElementById('imageViewerModal');
    const img = document.getElementById('viewerImage');
    const iframe = document.getElementById('viewerIframe');
    const downloadBtn = document.getElementById('downloadDocBtn');
    const titleEl = document.getElementById('docModalTitle');

    if (titleEl) {
      titleEl.innerText = title || 'ดูเอกสารแนบ';
    }
    if (downloadBtn) {
      downloadBtn.href = filePath;
    }

    const cleanPath = filePath.split('?')[0].split('#')[0];
    const ext = cleanPath.split('.').pop().toLowerCase();

    if (ext === 'pdf') {
      img.classList.add('hidden');
      img.src = '';
      iframe.src = filePath;
      iframe.classList.remove('hidden');
    } else {
      iframe.classList.add('hidden');
      iframe.src = '';
      img.src = filePath;
      img.classList.remove('hidden');
    }

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    if (window.lucide) lucide.createIcons();
  }

  function closeDocModal() {
    const modal = document.getElementById('imageViewerModal');
    const iframe = document.getElementById('viewerIframe');
    const img = document.getElementById('viewerImage');

    if (modal) modal.classList.add('hidden');
    if (iframe) iframe.src = '';
    if (img) img.src = '';
    document.body.style.overflow = '';
  }

  // รองรับชื่อฟังก์ชันเดิม openImageModal ให้เปิด Modal มาตรฐานเดียวกัน
  function openImageModal(src, title) {
    openDocModal(src, title || 'ดูรูปภาพเอกสาร');
  }

  function closeImageModal() {
    closeDocModal();
  }

  // ปิดเมื่อกดปุ่ม ESC
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeDocModal();
    }
  });
</script>
