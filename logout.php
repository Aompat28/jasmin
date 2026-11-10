<?php
session_start();
session_unset();
session_destroy();
$redirect = "login.php";
if (isset($_GET['msg']) && $_GET['msg'] === 'pwd_changed') {
    $redirect .= "?msg=pwd_changed";
}
header("Location: " . $redirect);
exit();
?>