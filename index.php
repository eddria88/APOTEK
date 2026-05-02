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

        </div>
    </div>
</body>
</html>


            <form method="POST" id="loginForm" autocomplete="off">

                <!-- Username -->
                <div class="inputBox">
                    <input type="text" name="username" id="username"
                           placeholder="Masukkan username" required>
                </div>

                <!-- Password -->
                <div class="password-wrapper">
                    <input type="password" name="password" id="passwordInput"
                           placeholder="Masukkan password" required>
                    <span class="toggle-password" onclick="togglePassword()" title="Tampilkan/Sembunyikan Password">
                        <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                            <line id="eyeSlash" x1="2" y1="2" x2="22" y2="22"
                                  style="display:none;"/>
                        </svg>
                    </span>
                </div>

                <button type="submit" name="login">Login</button>

            </form>
        </div>

    </div>

    <script>
        function togglePassword() {
            const input   = document.getElementById('passwordInput');
            const slash   = document.getElementById('eyeSlash');
            const isHidden = input.type === 'password';

            input.type    = isHidden ? 'text' : 'password';
            slash.style.display = isHidden ? 'block' : 'none';
        }
    </script>
</body>

</html>

