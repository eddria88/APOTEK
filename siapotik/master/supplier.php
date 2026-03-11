<?php
session_start();
require_once "../koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

$username = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user = mysqli_fetch_assoc($queryUser);

if (isset($_POST['simpan'])) {

    $nama   = mysqli_real_escape_string($conn, $_POST['nama_supplier']);
    $alamat = mysqli_real_escape_string($conn, $_POST['alamat']);
    $telp   = mysqli_real_escape_string($conn, $_POST['no_telp']);
    $email  = mysqli_real_escape_string($conn, $_POST['email']);

    mysqli_query($conn, "INSERT INTO supplier 
        (nama_supplier, alamat, no_telp, email) 
        VALUES ('$nama','$alamat','$telp','$email')");

    header("Location: supplier.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Master Supplier</title>

    <link href="../css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
</head>

<body>

<nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
    <a class="navbar-brand ps-3" href="../dashboard.php">APOTEK</a>
    <button class="btn btn-link btn-sm order-1 order-lg-0 me-4" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>

    <ul class="navbar-nav ms-auto me-3">
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                <i class="fas fa-user fa-fw"></i> <?= $user['nama_user']; ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text">Role: <?= $user['role']; ?></span></li>
                <li><hr class="dropdown-divider" /></li>
                <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
            </ul>
        </li>
    </ul>
</nav>

<div id="layoutSidenav">

    <!-- SIDEBAR -->
    <div id="layoutSidenav_nav">
        <nav class="sb-sidenav accordion sb-sidenav-dark">

            <div class="sb-sidenav-menu">
                <div class="nav">

                    <div class="sb-sidenav-menu-heading">Core</div>
                    <a class="nav-link" href="../dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i>
                        Dashboard
                    </a>

                    <div class="sb-sidenav-menu-heading">Master Data</div>
                    <a class="nav-link" href="kategori.php">Kategori</a>
                    <a class="nav-link active" href="supplier.php">Supplier</a>
                    <a class="nav-link" href="obat.php">Obat</a>

                    <div class="sb-sidenav-menu-heading">Transaksi</div>
                    <a class="nav-link" href="../transaksi/pembelian.php">Pembelian</a>
                    <a class="nav-link" href="../transaksi/penjualan.php">Penjualan</a>

                    <div class="sb-sidenav-menu-heading">Laporan</div>
                    <a class="nav-link" href="../laporan/laporan_penjualan.php">Penjualan</a>
                    <a class="nav-link" href="../laporan/laporan_pembelian.php">Pembelian</a>
                    <a class="nav-link" href="../laporan/laporan_stok.php">Stok</a>

                </div>
            </div>

            <div class="sb-sidenav-footer">
                <div class="small">Logged in as:</div>
                <?= $user['nama_user']; ?>
            </div>

        </nav>
    </div>

    <!-- CONTENT -->
    <div id="layoutSidenav_content">
        <main>
            <div class="container-fluid px-4">

                <h1 class="mt-4">Master Supplier</h1>
                <hr>

                <!-- FORM TAMBAH -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="fas fa-plus"></i> Tambah Supplier
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">

                            <div class="col-md-6">
                                <input type="text" name="nama_supplier" 
                                    class="form-control" placeholder="Nama Supplier" required>
                            </div>

                            <div class="col-md-6">
                                <input type="text" name="alamat" 
                                    class="form-control" placeholder="Alamat">
                            </div>

                            <div class="col-md-6">
                                <input type="text" name="no_telp" 
                                    class="form-control" placeholder="No Telp">
                            </div>

                            <div class="col-md-6">
                                <input type="email" name="email" 
                                    class="form-control" placeholder="Email">
                            </div>

                            <div class="col-md-12">
                                <button type="submit" name="simpan" class="btn btn-primary">
                                    Simpan
                                </button>
                            </div>

                        </form>
                    </div>
                </div>

                <!-- TABEL DATA -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-table"></i> Data Supplier
                    </div>
                    <div class="card-body">

                        <table class="table table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th>No</th>
                                    <th>Nama</th>
                                    <th>Alamat</th>
                                    <th>No Telp</th>
                                    <th>Email</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $no = 1;
                            $data = mysqli_query($conn, "SELECT * FROM supplier ORDER BY id_supplier DESC");

                            while ($row = mysqli_fetch_assoc($data)) {
                                echo "<tr>
                                        <td>$no</td>
                                        <td>{$row['nama_supplier']}</td>
                                        <td>{$row['alamat']}</td>
                                        <td>{$row['no_telp']}</td>
                                        <td>{$row['email']}</td>
                                      </tr>";
                                $no++;
                            }
                            ?>
                            </tbody>
                        </table>

                    </div>
                </div>

            </div>
        </main>

        <footer class="py-4 bg-light mt-auto">
            <div class="container-fluid px-4 text-center small text-muted">
                Sistem Informasi Apotek 2026
            </div>
        </footer>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/scripts.js"></script>

</body>
</html>
