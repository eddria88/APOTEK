<!-- <?php
session_start();
require_once "koneksi.php";

if (isset($_POST['login'])) {

    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = mysqli_real_escape_string($conn, $_POST['password']);

    $query = mysqli_query($conn, "SELECT * FROM users WHERE username = '$username' AND password = '$password'");

    if (mysqli_num_rows($query) > 0) {

        $data = mysqli_fetch_assoc($query);

        $_SESSION['user'] = $data['username'];
        $_SESSION['nama_user'] = $data['nama_user'];
        $_SESSION['role'] = $data['role'];

        header("Location: dashboard.php");
        exit;

    } else {
        $error = "Username atau Password salah!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login Sistem Apotek</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow">
                <div class="card-header text-center">
                    <h4>Login Sistem Apotek</h4>
                </div>
                <div class="card-body">

                    <?php if (isset($error)) : ?>
                        <div class="alert alert-danger">
                            <?= $error; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label>Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label>Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>

                        <button type="submit" name="login" class="btn btn-primary w-100">
                            Login
                        </button>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html> -->

<?php
 include "koneksi.php";  ?>

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
            $pass  = md5($_POST['password']);

            $q = mysqli_query($conn, "SELECT * FROM users WHERE username='$username' AND password='$pass'");
            if (mysqli_num_rows($q) > 0) {
                $_SESSION['user'] = mysqli_fetch_assoc($q);
                header("Location: dashboard.php");
                exit;
            } else {
                echo "<p style='color:red'>Login gagal</p>";
            }
        }
        ?>
    </div>
</div>