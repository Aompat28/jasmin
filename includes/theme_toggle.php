<?php
// includes/theme_toggle.php
// ปุ่มเลือกสลับธีม 2 สี (มืด / สว่าง)
?>
<div class="theme-toggle-pill" role="group" aria-label="Theme Selector">
  <button type="button" onclick="setAppTheme('dark')" class="theme-toggle-btn theme-btn-dark" title="ธีมมืด (Dark Theme)">
    <i data-lucide="moon" class="w-3.5 h-3.5 text-indigo-400"></i>
    <span class="hidden sm:inline">มืด</span>
  </button>
  <button type="button" onclick="setAppTheme('light')" class="theme-toggle-btn theme-btn-light" title="ธีมสว่าง (Light Theme)">
    <i data-lucide="sun" class="w-3.5 h-3.5 text-amber-500"></i>
    <span class="hidden sm:inline">สว่าง</span>
  </button>
</div>

