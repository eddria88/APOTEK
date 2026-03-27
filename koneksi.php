<?php
$host = "localhost";
$user = "amel";
$pass = "12345";
$db   = "db_apotek"; 

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

// Set timezone Indonesia
date_default_timezone_set("Asia/Jakarta");
?>
