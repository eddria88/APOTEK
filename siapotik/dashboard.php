<?php
session_start();
require_once "koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$username = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user = mysqli_fetch_assoc($queryUser);

$total_obat = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM obat"));
$total_supplier = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM supplier"));
$total_penjualan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM penjualan"));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Dashboard - Sistem Apotek</title>

    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
</head>

<body>

<nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
    <a class="navbar-brand ps-3" href="#">APOTEK</a>
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
                <li><a class="dropdown-item" href="logout.php">Logout</a></li>
            </ul>
        </li>
    </ul>
</nav>

<div id="layoutSidenav">

    <!-- SIDEBAR DARK -->
    <div id="layoutSidenav_nav">
        <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">

            <div class="sb-sidenav-menu">
                <div class="nav">

                    <div class="sb-sidenav-menu-heading">Core</div>
                    <a class="nav-link" href="dashboard.php">
                        <div class="sb-nav-link-icon">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        Dashboard
                    </a>

                    <div class="sb-sidenav-menu-heading">Master Data</div>
                    <a class="nav-link" href="master/kategori.php">Kategori</a>
                    <a class="nav-link" href="master/supplier.php">Supplier</a>
                    <a class="nav-link" href="master/obat.php">Obat</a>

                    <div class="sb-sidenav-menu-heading">Transaksi</div>
                    <a class="nav-link" href="transaksi/pembelian.php">Pembelian</a>
                    <a class="nav-link" href="transaksi/penjualan.php">Penjualan</a>

                    <div class="sb-sidenav-menu-heading">Laporan</div>
                    <a class="nav-link" href="laporan/laporan_penjualan.php">Penjualan</a>
                    <a class="nav-link" href="laporan/laporan_pembelian.php">Pembelian</a>
                    <a class="nav-link" href="laporan/laporan_stok.php">Stok</a>

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
                <h1 class="mt-4">Dashboard</h1>

                <ol class="breadcrumb mb-4">
                    <li class="breadcrumb-item active">Sistem Informasi Apotek</li>
                </ol>

                <div class="row">

                    <div class="col-xl-4 col-md-6">
                        <div class="card bg-primary text-white mb-4">
                            <div class="card-body">
                                Total Obat
                                <h3><?= $total_obat['total']; ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6">
                        <div class="card bg-success text-white mb-4">
                            <div class="card-body">
                                Total Supplier
                                <h3><?= $total_supplier['total']; ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6">
                        <div class="card bg-warning text-white mb-4">
                            <div class="card-body">
                                Total Penjualan
                                <h3><?= $total_penjualan['total']; ?></h3>
                            </div>
                        </div>
                    </div>

                </div>

                <div style="height: 500px;"></div>

            </div>
        </main>

        <footer class="py-4 bg-light mt-auto">
            <div class="container-fluid px-4 text-center small text-muted">
                Copyright &copy; Sistem Apotek 2026
            </div>
        </footer>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/scripts.js"></script>

</body>
</html>
