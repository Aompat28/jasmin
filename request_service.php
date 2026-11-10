<?php
// request_service.php - Redirect fallback to tenant_request.php
header("Location: tenant_request.php", true, 302);
exit();

