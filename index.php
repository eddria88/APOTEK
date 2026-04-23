<?php
session_start();
include "koneksi.php";
?>
<!DOCTYPE html>
<html>
<head>
    <title>HealPlus - LOGIN</title>
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-left">
            <h1>HealPlus</h1>
            <p>Sistem Kasir Apotek HEALPLUS</p>
        </div>
        <div class="login-right">
            <h3>Selamat Datang</h3>
            <p>Silahkan Login Untuk Melanjutkan</p>
            <form method="POST">
                <input type="text" name="username" placeholder="Masukkan username" required>
                <input type="password" name="password" placeholder="Masukkan password" required>
                <button name="login">Login</button>
            </form>
            <?php
            if (isset($_POST['login'])) {
                $username = $_POST['username'];
                $pass = md5($_POST['password']);

                // PERBAIKAN: ganti mysqli_query biasa dengan prepared statement
                $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE username = ? AND password = ?");
                mysqli_stmt_bind_param($stmt, "ss", $username, $pass);
                mysqli_stmt_execute($stmt);
                $q = mysqli_stmt_get_result($stmt);

                if (mysqli_num_rows($q) > 0) {
                    $data = mysqli_fetch_assoc($q);
                    $_SESSION['user'] = $data['username'];
                    $_SESSION['nama_user'] = $data['nama_user'];
                    $_SESSION['role'] = $data['role'];
                    header("Location: dashboard.php");
                    exit;
                } else {
                    echo "<p style='color:red'>Username atau Password salah</p>";
                }
                mysqli_stmt_close($stmt);
            }
            ?>
        </div>
    </div>
</body>
</html>

<!-- jadi allysa untuk tanda `?` itu adalah placeholder sehingga `username` apapun yang diketik akan diperlakukan sebagai data biasa, bukan bagian dari query. Jadi `' OR 1=1 #` tidak akan dieksekusi sebagai SQL jadi ga bisa bypass lagi -->