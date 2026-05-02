<?php
session_start();
require_once "koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$username  = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user      = mysqli_fetch_assoc($queryUser);

// ── Stats ──
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM obat"));
$total_obat = $row['total'];

$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM supplier"));
$total_supplier = $row['total'];

$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM penjualan"));
$total_transaksi = $row['total'];

// Hitung total member pembeli dari tabel member
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM member"));
$total_member = $row['total'];

// Penjualan hari ini
$today = date('Y-m-d');
$pj_today = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COALESCE(SUM(total),0) as total, COUNT(*) as jumlah FROM penjualan WHERE DATE(tanggal)='$today'"
));

// Stok hampir habis (stok <= stok_minimum atau <= 10)
$row_stok = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) as total FROM obat WHERE stok <= COALESCE(stok_minimum, 10)"
));
$stok_tipis = $row_stok['total'];

// Obat expired (cek kolom expired_date jika ada)
$colExp = mysqli_query($conn, "SHOW COLUMNS FROM obat LIKE 'expired_date'");
$obat_expired = 0;
if (mysqli_num_rows($colExp) > 0) {
    $row_exp = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) as total FROM obat WHERE expired_date < CURDATE() AND expired_date != '0000-00-00' AND expired_date IS NOT NULL"
    ));
    $obat_expired = $row_exp['total'];
}

// Penjualan 7 hari terakhir
$chart_labels = [];
$chart_data   = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $lbl = date('d/m', strtotime("-$i days"));
    $row_chart = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COALESCE(SUM(total),0) as s FROM penjualan WHERE DATE(tanggal)='$d'"
    ));
    $val = $row_chart['s'];
    $chart_labels[] = $lbl;
    $chart_data[]   = (float)$val;
}

// Metode pembayaran
$metode_result = mysqli_query(
    $conn,
    "SELECT metode_pembayaran as metode, COUNT(*) as jumlah FROM penjualan GROUP BY metode_pembayaran"
);
$metode_labels = [];
$metode_data   = [];
if ($metode_result) {
    while ($m = mysqli_fetch_assoc($metode_result)) {
        $metode_labels[] = $m['metode'] ?: 'Lainnya';
        $metode_data[]   = (int)$m['jumlah'];
    }
}
if (empty($metode_labels)) {
    $metode_labels = ['Tunai', 'Transfer', 'E-Wallet'];
    $metode_data   = [0, 0, 0];
}

// Transaksi terbaru hari ini (maks 5)
$today = date('Y-m-d');
$recent = mysqli_query(
    $conn,
    "SELECT p.*, u.nama_user, m.nama_lengkap as nama_member,
            (SELECT COUNT(*) FROM detail_penjualan dp WHERE dp.id_penjualan=p.id_penjualan) as jumlah_item
     FROM penjualan p
     LEFT JOIN users u ON p.id_user=u.id_user
     LEFT JOIN member m ON p.id_member=m.id_member
     WHERE DATE(p.tanggal)='$today'
     ORDER BY p.id_penjualan DESC LIMIT 5"
);
$recentRows = [];
if ($recent) while ($r = mysqli_fetch_assoc($recent)) $recentRows[] = $r;

// Persen perubahan penjualan vs kemarin
$yesterday = date('Y-m-d', strtotime('-1 day'));
$row_yesterday = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COALESCE(SUM(total),0) as total FROM penjualan WHERE DATE(tanggal)='$yesterday'"
));
$pj_yesterday = (float)$row_yesterday['total'];
$pj_today_val = (float)$pj_today['total'];
$pct_change = $pj_yesterday > 0 ? round((($pj_today_val - $pj_yesterday) / $pj_yesterday) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Dashboard — Apotek</title>
    <link rel="stylesheet" href="css/navigation.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>

<body>

    <!-- TOP NAV -->
    <nav class="topnav">
        <a href="dashboard.php" class="sb-brand">
            <img src="uploads/logo.png" alt="Logo Apotek" style="height: 50px;" class="logo">
        </a>
        <div class="breadcrumb">
            <i class="fas fa-chevron-right"></i>
            <span class="current">Dashboard</span>
        </div>
        <div class="topnav-right">
            <div class="user-info" class="ddwrap" id="ddwrap" onclick="toggleDropdown()">
                <div class="user-texts">
                    <div class="uname"><?= htmlspecialchars($user['nama_user']) ?></div>
                    <div class="urole"><?= htmlspecialchars($user['role']) ?></div>
                </div>
                <div>
                    <div class="user-avatar"><?= strtoupper(substr($user['nama_user'], 0, 1)) ?>
                    </div>
                    <div class="ddmenu" id="ddmenu">
                        <span class="role-lbl">Role: <?= htmlspecialchars($user['role']) ?></span>
                        <hr>
                        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="app-body">
        <!-- SIDEBAR -->
        <aside class="sidebar">
<<<<<<< HEAD
            <div class="sb-sec">Core</div>
            <a class="sb-link active" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
=======
            <?php if ($user['role'] != 'admin'): ?>
                <div class="sb-sec">Core</div>
                <a class="sb-link active" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <?php endif; ?>
>>>>>>> bd16fe67f3d2c39d24208074f6ecd7987812c103
            <div class="sb-sec">Master Data</div>
            <a class="sb-link" href="master/kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <?php if ($user['role'] != 'kasir'): ?>
                <a class="sb-link" href="master/supplier.php"><i class="fas fa-truck"></i> Supplier</a>
            <?php endif; ?>
            <a class="sb-link" href="master/obat.php"><i class="fas fa-pills"></i> Obat</a>
            <a class="sb-link" href="master/member.php"><i class="fas fa-user-friends"></i> Member</a>
            <?php if ($user['role'] == 'owner'): ?>
<<<<<<< HEAD
            <div class="sb-sec">Laporan</div>
            <a class="sb-link" href="laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
            <a class="sb-link" href="laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
            <a class="sb-link" href="laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
=======
                <div class="sb-sec">Transaksi</div>
                <a class="sb-link" href="transaksi/pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
                <a class="sb-link" href="transaksi/penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
                <div class="sb-sec">Laporan</div>
                <a class="sb-link" href="laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
                <a class="sb-link" href="laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
                <a class="sb-link" href="laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
>>>>>>> bd16fe67f3d2c39d24208074f6ecd7987812c103
            <?php elseif ($user['role'] == 'kasir'): ?>
                <div class="sb-sec">Transaksi</div>
                <a class="sb-link" href="transaksi/penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
            <?php endif; ?>
            <div class="sb-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <div class="main-content">

            <!-- PAGE HEADER -->
            <div class="page-header">
                <h2>Dashboard Overview</h2>
                <p>Selamat datang kembali — berikut ringkasan aktivitas hari ini, <?= date('d F Y') ?></p>
            </div>

            <!-- STATS GRID -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon green"><i class="fas fa-wallet"></i></div>
                        <span class="stat-badge <?= $pct_change >= 0 ? 'green' : 'red' ?>">
                            <?= ($pct_change >= 0 ? '+' : '') . $pct_change ?>%
                        </span>
                    </div>
                    <p class="stat-label">Penjualan Hari Ini</p>
                    <h3 class="stat-value">Rp <?= number_format($pj_today_val, 0, ',', '.') ?></h3>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon blue"><i class="fas fa-shopping-cart"></i></div>
                        <span class="stat-badge blue">Transaksi</span>
                    </div>
                    <p class="stat-label">Total Transaksi Hari Ini</p>
                    <h3 class="stat-value"><?= (int)$pj_today['jumlah'] ?></h3>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon amber"><i class="fas fa-exclamation-triangle"></i></div>
                        <span class="stat-badge amber"><?= $stok_tipis > 0 ? 'Perlu Perhatian' : 'Aman' ?></span>
                    </div>
                    <p class="stat-label">Stok Hampir Habis</p>
                    <h3 class="stat-value"><?= $stok_tipis ?> Item</h3>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon red"><i class="fas fa-calendar-times"></i></div>
                        <span class="stat-badge <?= $obat_expired > 0 ? 'red' : 'green' ?>"><?= $obat_expired > 0 ? 'Segera' : 'Aman' ?></span>
                    </div>
                    <p class="stat-label">Obat Expired</p>
                    <h3 class="stat-value"><?= $obat_expired ?> Item</h3>
                </div>
            </div>

            <!-- CHARTS -->
            <div class="charts-row">
                <div class="chart-card">
                    <h3>Penjualan 7 Hari Terakhir</h3>
                    <canvas id="salesChart" height="160"></canvas>
                </div>
                <div class="chart-card">
                    <h3>Metode Pembayaran</h3>
                    <canvas id="paymentChart" height="160"></canvas>
                </div>
            </div>

            <!-- SUMMARY CARDS -->
            <div class="summary-row">
                <div class="summary-card">
                    <div class="sum-icon green"><i class="fas fa-pills"></i></div>
                    <div>
                        <div class="sum-label">Total Jenis Obat</div>
                        <div class="sum-val"><?= $total_obat ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="sum-icon blue"><i class="fas fa-truck"></i></div>
                    <div>
                        <div class="sum-label">Total Supplier</div>
                        <div class="sum-val"><?= $total_supplier ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="sum-icon amber"><i class="fas fa-receipt"></i></div>
                    <div>
                        <div class="sum-label">Total Transaksi (All)</div>
                        <div class="sum-val"><?= $total_transaksi ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="sum-icon purple"><i class="fas fa-id-card"></i></div>
                    <div>
                        <div class="sum-label">Member Terdaftar</div>
                        <div class="sum-val"><?= $total_member ?></div>
                    </div>
                </div>
            </div>

            <!-- RECENT TRANSACTIONS -->
            <div class="table-card">
                <div class="table-head-row">
                    <h3>Transaksi Terbaru</h3>
                    <a href="transaksi/penjualan.php" class="link-btn">Lihat Semua →</a>
                </div>
                <div style="overflow-x:auto">
                    <table class="dtable">
                        <thead>
                            <tr>
                                <th>ID Transaksi</th>
                                <th>Waktu</th>
                                <th>Kasir</th>
                                <th>Member</th>
                                <th>Item</th>
                                <th>Metode</th>
                                <th class="right">Total</th>
                                <th class="center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentRows)): ?>
                                <tr>
                                    <td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">
                                        Belum ada transaksi
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentRows as $r): ?>
                                    <?php
                                    // Sesuaikan dengan ENUM: Tunai | Tranfer_Bank | E_Wallet | Member
                                    $metode = $r['metode_pembayaran'] ?? 'Tunai';

                                    if ($metode == 'Tranfer_Bank') {
                                        $badge = 'blue';
                                        $label = 'Transfer Bank';
                                    } elseif ($metode == 'E_Wallet') {
                                        $badge = 'amber';
                                        $label = 'E-Wallet';
                                    } else {
                                        $badge = 'green';
                                        $label = 'Tunai';
                                    }

                                    $trxId = '#TRX-' . str_pad($r['id_penjualan'], 3, '0', STR_PAD_LEFT);
                                    $waktu = date('H:i', strtotime($r['tanggal']));
                                    $items = (int)($r['jumlah_item'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><span class="td-mono"><?= $trxId ?></span></td>
                                        <td class="td-muted"><?= $waktu ?></td>
                                        <td class="td-muted"><?= htmlspecialchars($r['nama_user'] ?? '—') ?></td>
                                        <td>
                                            <?php if (!empty($r['nama_member'])): ?>
                                                <span class="badge purple" style="gap:5px">
                                                    <i class="fas fa-user-tag" style="font-size:10px"></i>
                                                    <?= htmlspecialchars($r['nama_member']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="font-size:12px;color:var(--muted)">Umum</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $items ?> Item</td>
                                        <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                                        <td class="td-right">Rp <?= number_format($r['total'], 0, ',', '.') ?></td>
                                        <td style="text-align:center"><span class="badge success">Sukses</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- end main-content -->
    </div><!-- end app-body -->

    <script>
        // ── Sales Chart ──
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        const salesGrad = salesCtx.createLinearGradient(0, 0, 0, 200);
        salesGrad.addColorStop(0, 'rgba(45,106,79,.18)');
        salesGrad.addColorStop(1, 'rgba(45,106,79,0)');

        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'Penjualan',
                    data: <?= json_encode($chart_data) ?>,
                    borderColor: '#2d6a4f',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#2d6a4f',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    backgroundColor: salesGrad,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e0e6de'
                        },
                        ticks: {
                            font: {
                                family: 'Plus Jakarta Sans',
                                size: 11
                            },
                            color: '#6b7e6b',
                            callback: v => 'Rp ' + (v >= 1000000 ? (v / 1000000).toFixed(1) + 'jt' : v >= 1000 ? (v / 1000).toFixed(0) + 'rb' : v)
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                family: 'Plus Jakarta Sans',
                                size: 11
                            },
                            color: '#6b7e6b'
                        }
                    }
                }
            }
        });

        // ── Payment Donut ──
        const payCtx = document.getElementById('paymentChart').getContext('2d');
        new Chart(payCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($metode_labels) ?>,
                datasets: [{
                    data: <?= json_encode($metode_data) ?>,
                    backgroundColor: ['#d8f3dc', '#ddeeff', '#f3eeff', '#fff3e0', '#ffeef0'],
                    borderColor: ['#40916c', '#1d6fa4', '#7c3aed', '#e07b00', '#e63946'],
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                family: 'Plus Jakarta Sans',
                                size: 12
                            },
                            padding: 14,
                            boxWidth: 12,
                            borderRadius: 4
                        }
                    }
                }
            }
        });

        // ── Dropdown user (klik avatar untuk buka/tutup) ──
        function toggleDropdown() {
            var menu = document.getElementById('ddmenu');
            if (menu.style.display === 'block') {
                menu.style.display = 'none';
            } else {
                menu.style.display = 'block';
            }
        }

        // Klik di luar dropdown = tutup otomatis
        document.addEventListener('click', function(e) {
            var wrap = document.getElementById('ddwrap');
            if (wrap && !wrap.contains(e.target)) {
                document.getElementById('ddmenu').style.display = 'none';
            }
        });
    </script>
</body>

</html>