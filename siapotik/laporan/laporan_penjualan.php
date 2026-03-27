<?php
session_start();
require_once "../koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

$tanggal_awal = $_GET['tanggal_awal'] ?? '';
$tanggal_akhir = $_GET['tanggal_akhir'] ?? '';

$where = "";

if (!empty($tanggal_awal) && !empty($tanggal_akhir)) {
    $where = "WHERE DATE(tanggal) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'";
}

$query = mysqli_query($conn, "
    SELECT * FROM penjualan
    $where
    ORDER BY tanggal DESC
");

$total_semua = 0;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Laporan Penjualan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="container mt-4">

    <h3>Laporan Penjualan</h3>
    <hr>

    <form method="GET" class="row g-3 mb-4">
        <div class="col-md-3">
            <label>Dari Tanggal</label>
            <input type="date" name="tanggal_awal" class="form-control"
                value="<?= $tanggal_awal ?>">
        </div>

        <div class="col-md-3">
            <label>Sampai Tanggal</label>
            <input type="date" name="tanggal_akhir" class="form-control"
                value="<?= $tanggal_akhir ?>">
        </div>

        <div class="col-md-3 align-self-end">
            <button type="submit" class="btn btn-primary">
                Tampilkan
            </button>

            <button onclick="window.print()" type="button"
                class="btn btn-success">
                Cetak
            </button>
        </div>
    </form>

    <table class="table table-bordered">
        <thead class="table-dark">
            <tr>
                <th>No</th>
                <th>Tanggal</th>
                <th>Total</th>
                <th>Bayar</th>
                <th>Kembalian</th>
            </tr>
        </thead>
        <tbody>

        <?php
        $no = 1;
        while ($row = mysqli_fetch_assoc($query)) {

            $total_semua += $row['total'];

            echo "<tr>
                    <td>$no</td>
                    <td>$row[tanggal]</td>
                    <td>Rp ".number_format($row['total'])."</td>
                    <td>Rp ".number_format($row['bayar'])."</td>
                    <td>Rp ".number_format($row['kembalian'])."</td>
                  </tr>";

            $no++;
        }
        ?>

        </tbody>
        <tfoot>
            <tr>
                <th colspan="2" class="text-end">Total Keseluruhan</th>
                <th colspan="3">
                    Rp <?= number_format($total_semua); ?>
                </th>
            </tr>
        </tfoot>
    </table>

    <a href="../dashboard.php" class="btn btn-secondary">
        Kembali
    </a>

</div>

</body>
</html>
