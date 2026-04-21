<?php
session_start();

// Hapus semua data session
session_unset();
session_destroy();

// Kembali ke halaman login
header("Location: index.php");
exit;
?>
