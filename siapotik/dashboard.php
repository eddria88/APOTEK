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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        :root {
            --green: #2d6a4f;
            --green-mid: #40916c;
            --green-light: #52b788;
            --green-pale: #d8f3dc;
            --green-btn: #1b4332;
            --bg: #f4f6f3;
            --surface: #fff;
            --border: #e0e6de;
            --text: #1a2e1a;
            --muted: #6b7e6b;
            --red: #e63946;
            --red-pale: #ffeef0;
            --blue: #1d6fa4;
            --blue-pale: #ddeeff;
            --amber: #e07b00;
            --amber-pale: #fff3e0;
            --purple: #7c3aed;
            --purple-pale: #f3eeff;
            --radius: 16px;
            --shadow: 0 2px 12px rgba(0, 0, 0, .07);
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column
        }

        /* ── TOP NAV ── */
        .topnav {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            height: 60px;
            display: flex;
            align-items: center;
            padding: 0 28px;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 6px rgba(0, 0, 0, .05)
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13.5px;
            color: var(--muted)
        }

        .breadcrumb .current {
            font-weight: 700;
            color: var(--text)
        }

        .breadcrumb i {
            font-size: 10px
        }

        .topnav-right {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 14px
        }

        .icon-btn {
            width: 38px;
            height: 38px;
            border: 1px solid var(--border);
            border-radius: 11px;
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--muted);
            font-size: 15px;
            position: relative;
            transition: border-color .2s, color .2s
        }

        .icon-btn:hover {
            border-color: var(--green-light);
            color: var(--green)
        }

        .notif-dot {
            position: absolute;
            top: 7px;
            right: 7px;
            width: 7px;
            height: 7px;
            background: var(--red);
            border-radius: 50%;
            border: 1.5px solid var(--surface)
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px
        }

        .user-texts {
            text-align: right
        }

        .user-texts .uname {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2
        }

        .user-texts .urole {
            font-size: 11.5px;
            color: var(--muted)
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--green);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer
        }

        .ddwrap {
            position: relative
        }

        .ddmenu {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .1);
            min-width: 180px;
            padding: 8px;
            display: none;
            z-index: 200
        }

        /* dropdown dikendalikan JS */
        .ddmenu a,
        .ddmenu span {
            display: block;
            padding: 8px 12px;
            font-size: 13px;
            color: var(--text);
            border-radius: 8px;
            text-decoration: none
        }

        .ddmenu a:hover {
            background: var(--green-pale);
            color: var(--green)
        }

        .ddmenu .role-lbl {
            color: var(--muted);
            font-size: 12px
        }

        .ddmenu hr {
            border: none;
            border-top: 1px solid var(--border);
            margin: 4px 0
        }

        .ddmenu .logout {
            color: var(--red) !important
        }

        .ddmenu .logout:hover {
            background: var(--red-pale) !important
        }

        /* ── LAYOUT ── */
        .app-body {
            display: flex;
            flex: 1;
            overflow: hidden;
            height: calc(100vh - 60px)
        }

        .sidebar {
            width: 220px;
            min-width: 220px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            overflow-y: auto;
            padding: 16px 0;
            display: flex;
            flex-direction: column
        }

        .sb-sec {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--muted);
            padding: 8px 20px 4px
        }

        .sb-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 20px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--muted);
            text-decoration: none;
            transition: background .15s, color .15s
        }

        .sb-link i {
            width: 16px;
            text-align: center;
            font-size: 13px
        }

        .sb-link:hover {
            background: var(--green-pale);
            color: var(--green)
        }

        .sb-link.active {
            background: var(--green-pale);
            color: var(--green);
            font-weight: 700;
            border-right: 3px solid var(--green)
        }

        .sb-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 20px 16px;
            font-size: 17px;
            font-weight: 800;
            color: var(--green);
            letter-spacing: -.3px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 8px
        }

        .sb-brand i {
            color: var(--green-light)
        }

        .sb-footer {
            margin-top: auto;
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            font-size: 12px;
            color: var(--muted)
        }

        .sb-footer strong {
            display: block;
            color: var(--text);
            font-size: 13px
        }

        /* ── MAIN ── */
        .main-content {
            flex: 1;
            overflow-y: auto;
            padding: 28px;
            display: flex;
            flex-direction: column;
            gap: 24px
        }

        /* PAGE HEADER */
        .page-header h2 {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -.5px
        }

        .page-header p {
            font-size: 14px;
            color: var(--muted);
            margin-top: 4px
        }

        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px
        }

        @media(max-width:1100px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        .stat-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 22px 22px 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            border: 1.5px solid transparent;
            transition: border-color .2s, transform .15s, box-shadow .2s
        }

        .stat-card:hover {
            border-color: var(--border);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, .09)
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px
        }

        .stat-icon.green {
            background: var(--green-pale);
            color: var(--green-mid)
        }

        .stat-icon.blue {
            background: var(--blue-pale);
            color: var(--blue)
        }

        .stat-icon.amber {
            background: var(--amber-pale);
            color: var(--amber)
        }

        .stat-icon.red {
            background: var(--red-pale);
            color: var(--red)
        }

        .stat-badge {
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px
        }

        .stat-badge.green {
            background: var(--green-pale);
            color: var(--green)
        }

        .stat-badge.blue {
            background: var(--blue-pale);
            color: var(--blue)
        }

        .stat-badge.amber {
            background: var(--amber-pale);
            color: var(--amber)
        }

        .stat-badge.red {
            background: var(--red-pale);
            color: var(--red)
        }

        .stat-label {
            font-size: 13px;
            color: var(--muted);
            font-weight: 500
        }

        .stat-value {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -.5px;
            line-height: 1.1
        }

        /* CHARTS */
        .charts-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 16px
        }

        @media(max-width:900px) {
            .charts-row {
                grid-template-columns: 1fr
            }
        }

        .chart-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 22px
        }

        .chart-card h3 {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text)
        }

        /* TABLE CARD */
        .table-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden
        }

        .table-head-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px 14px;
            border-bottom: 1px solid var(--border)
        }

        .table-head-row h3 {
            font-size: 16px;
            font-weight: 700
        }

        .link-btn {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--green);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            transition: color .2s
        }

        .link-btn:hover {
            color: var(--green-btn)
        }

        table.dtable {
            width: 100%;
            border-collapse: collapse
        }

        .dtable thead th {
            background: var(--bg);
            padding: 10px 22px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .6px;
            border-bottom: 1px solid var(--border)
        }

        .dtable thead th.right {
            text-align: right
        }

        .dtable thead th.center {
            text-align: center
        }

        .dtable tbody td {
            padding: 15px 22px;
            border-bottom: 1px solid var(--border);
            font-size: 13.5px;
            vertical-align: middle
        }

        .dtable tbody tr:last-child td {
            border-bottom: none
        }

        .dtable tbody tr:hover td {
            background: #fafcfa
        }

        .td-mono {
            font-family: 'DM Mono', monospace;
            font-size: 13px;
            font-weight: 600;
            color: var(--text)
        }

        .td-muted {
            color: var(--muted)
        }

        .td-right {
            text-align: right;
            font-weight: 700
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700
        }

        .badge.green,
        .badge.tunai {
            background: var(--green-pale);
            color: var(--green)
        }

        .badge.blue,
        .badge.transfer {
            background: var(--blue-pale);
            color: var(--blue)
        }

        .badge.purple,
        .badge.bpjs {
            background: var(--purple-pale);
            color: var(--purple)
        }

        .badge.amber,
        .badge.ewallet {
            background: var(--amber-pale);
            color: var(--amber)
        }

        .badge.success {
            background: var(--green-pale);
            color: var(--green)
        }

        /* Summary cards (bottom) */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px
        }

        .summary-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px
        }

        .sum-icon {
            width: 48px;
            height: 48px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0
        }

        .sum-icon.green {
            background: var(--green-pale);
            color: var(--green)
        }

        .sum-icon.blue {
            background: var(--blue-pale);
            color: var(--blue)
        }

        .sum-icon.amber {
            background: var(--amber-pale);
            color: var(--amber)
        }

        .sum-icon.purple {
            background: var(--purple-pale);
            color: var(--purple)
        }

        .sum-label {
            font-size: 12.5px;
            color: var(--muted);
            font-weight: 500
        }

        .sum-val {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -.3px;
            margin-top: 2px
        }

        ::-webkit-scrollbar {
            width: 5px
        }

        ::-webkit-scrollbar-track {
            background: transparent
        }

        ::-webkit-scrollbar-thumb {
            background: #c8d8c8;
            border-radius: 4px
        }
    </style>
</head>

<body>

    <!-- TOP NAV -->
    <nav class="topnav">
        <div class="breadcrumb">
            <span>Dashboard</span>
            <i class="fas fa-chevron-right"></i>
            <span class="current">Overview</span>
        </div>
        <div class="topnav-right">
            <div class="icon-btn">
                <i class="fas fa-bell"></i>
                <?php if ($stok_tipis > 0): ?><span class="notif-dot"></span><?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-texts">
                    <div class="uname"><?= htmlspecialchars($user['nama_user']) ?></div>
                    <div class="urole"><?= htmlspecialchars($user['role']) ?></div>
                </div>
                <div class="ddwrap" id="ddwrap">
                    <div class="user-avatar" onclick="toggleDropdown()"><?= strtoupper(substr($user['nama_user'], 0, 1)) ?></div>
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
            <div class="sb-sec">Core</div>
            <a class="sb-link active" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <div class="sb-sec">Master Data</div>
            <a class="sb-link" href="master/kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <a class="sb-link" href="master/supplier.php"><i class="fas fa-truck"></i> Supplier</a>
            <a class="sb-link" href="master/obat.php"><i class="fas fa-pills"></i> Obat</a>
            <a class="sb-link" href="master/member.php"><i class="fas fa-user-friends"></i> Member</a>
            <div class="sb-sec">Transaksi</div>
            <a class="sb-link" href="transaksi/pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
            <a class="sb-link" href="transaksi/penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
            <div class="sb-sec">Laporan</div>
            <a class="sb-link" href="laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
            <a class="sb-link" href="laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
            <a class="sb-link" href="laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
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
