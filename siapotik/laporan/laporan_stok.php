<?php
session_start();
require_once "../koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

$query = mysqli_query($conn, "
SELECT 
    o.nama_obat,
    k.nama_kategori,
    o.stok,
    o.stok_minimum,
    p.batch,
    p.expired_date,
    p.jumlah
FROM pembelian p
LEFT JOIN obat o ON p.id_obat = o.id_obat
LEFT JOIN kategori k ON o.id_kategori = k.id_kategori
ORDER BY p.expired_date ASC
");

$total_item = mysqli_num_rows($query);
$today = date("Y-m-d");
?>

<!DOCTYPE html>
<html>

<head>
    <title>Laporan Stok Obat</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        @media print {

            button,
            a {
                display: none;
            }
        }
    </style>

</head>

<body>

    <div class="container mt-4">

        <h3>Laporan Stok Obat</h3>
        <hr>

        <button onclick="window.print()" class="btn btn-success mb-3">
            Cetak
        </button>

        <table class="table table-bordered">

            <thead class="table-dark">
                <tr>
                    <th>No</th>
                    <th>Nama Obat</th>
                    <th>Kategori</th>
                    <th>Batch</th>
                    <th>Jumlah Batch</th>
                    <th>Stok Total</th>
                    <th>Expired Date</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

                <?php
                $no = 1;

                while ($row = mysqli_fetch_assoc($query)) {

                    $status = "Aman";
                    $class = "";

                    if ($row['expired_date'] <= $today) {
                        $status = "Expired!";
                        $class = "table-danger";
                    } elseif ($row['expired_date'] <= date('Y-m-d', strtotime('+30 days'))) {
                        $status = "Hampir Expired";
                        $class = "table-warning";
                    } elseif ($row['stok'] <= $row['stok_minimum']) {
                        $status = "Stok Minimum";
                        $class = "table-warning";
                    }

                    echo "<tr class='$class'>

<td>$no</td>
<td>$row[nama_obat]</td>
<td>$row[nama_kategori]</td>
<td>$row[batch]</td>
<td>$row[jumlah]</td>
<td>$row[stok]</td>
<td>$row[expired_date]</td>
<td><strong>$status</strong></td>

</tr>";

                    $no++;
                }
                ?>

            </tbody>

            <tfoot>
                <tr>
                    <th colspan="7" class="text-end">Total Batch</th>
                    <th><?= $total_item; ?></th>
                </tr>
            </tfoot>

        </table>

        <a href="../dashboard.php" class="btn btn-secondary">
            Kembali
        </a>

    </div>

</body>

</html>