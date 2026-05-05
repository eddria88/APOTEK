<?php
session_start();
include "koneksi.php";
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <title>HealPlus - LOGIN</title>
    <link rel="stylesheet" href="css/login.css">
</head>

<body>
    <div class="login-wrapper">

        <!-- ===== LEFT PANEL ===== -->
        <div class="login-left">
            <img src="uploads/logo.png" class="logo" alt="HealPlus logo">
            <h2>Sistem Kasir Apotek HEALPLUS</h2>
            <p>Platform internal untuk mengelola transaksi, stok, dan laporan apotek dengan praktis</p>
            <img src="uploads/illustration.png" class="Illustration" alt="Login Illustration">
        </div>

        <!-- ===== RIGHT PANEL ===== -->
        <div class="login-right">
            <h1>Selamat Datang</h1>
            <p>Silahkan login untuk melanjutkan</p>

            <?php
            if (isset($_POST['login'])) {
                $username = $_POST['username'];
                $pass = md5($_POST['password']);

                $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE username = ? AND password = ?");
                mysqli_stmt_bind_param($stmt, "ss", $username, $pass);
                mysqli_stmt_execute($stmt);
                $q = mysqli_stmt_get_result($stmt);

                if (mysqli_num_rows($q) > 0) {
                    $data = mysqli_fetch_assoc($q);
                    $_SESSION['user']      = $data['username'];
                    $_SESSION['nama_user'] = $data['nama_user'];
                    $_SESSION['role']      = $data['role'];
                    header("Location: dashboard.php");
                    exit;
                } else {
                    echo '<p class="error-msg">Username atau Password salah</p>';
                }
                mysqli_stmt_close($stmt);
            }
            ?>
