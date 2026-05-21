<?php
session_start();
require_once "../koneksi.php";
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET NAMES utf8mb4");
// Nonaktifkan strict mode agar '0000-00-00' tidak error
mysqli_query($conn, "SET SESSION sql_mode = ''");

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SESSION['role'] != "admin" && $_SESSION['role'] != "gudang" && $_SESSION['role'] != "owner") {
    header("Location: ../dashboard.php");
    exit;
}

$username  = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user      = mysqli_fetch_assoc($queryUser);

// ── Active tab ──
$activeTab = $_GET['tab'] ?? 'stok';

// ───────────────────────────────────────────────
// TAB 1 — LAPORAN STOK
// ───────────────────────────────────────────────
$search        = mysqli_real_escape_string($conn, $_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_tahun  = $_GET['tahun'] ?? '';
$filter_bulan  = $_GET['bulan'] ?? '';

$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$today    = date("Y-m-d");
$in30days = date('Y-m-d', strtotime('+30 days'));

$conditions = [];
if (!empty($search)) {
    $conditions[] = "(v.nama_obat LIKE '%$search%')";
}
if (!empty($filter_tahun)) {
    $ft = (int)$filter_tahun;
    $conditions[] = "v.tahun = $ft";
}
if (!empty($filter_bulan)) {
    $fb = mysqli_real_escape_string($conn, $filter_bulan);
    $conditions[] = "v.bulan = '$fb'";
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$summaryQ = mysqli_query($conn, "
    SELECT
        COUNT(DISTINCT v.nama_obat) AS total_obat,
        SUM(v.masuk)                AS total_masuk,
        SUM(v.keluar)               AS total_keluar,
        SUM(v.sisa)                 AS total_sisa
    FROM db_apotek.v_laporan_stok_obat v
    $where
");
$summary = mysqli_fetch_assoc($summaryQ);

$allQ = mysqli_query($conn, "
    SELECT v.tahun, v.bulan, v.nama_obat, v.jumlah, v.masuk, v.keluar, v.sisa,
           COALESCE(o.stok_minimum, 0) AS stok_minimum
    FROM db_apotek.v_laporan_stok_obat v
    LEFT JOIN obat o ON o.nama_obat = v.nama_obat
    $where
    ORDER BY v.tahun DESC,
             FIELD(
                v.bulan COLLATE utf8mb4_general_ci,
                'Jan','Feb','Mar','Apr','Mei','Jun',
                'Jul','Agu','Sep','Okt','Nov','Des'
             ) DESC,
             v.nama_obat ASC
");
$allRows = [];
while ($r = mysqli_fetch_assoc($allQ)) {
    $sisa = (float)$r['sisa'];
    $min  = (float)$r['stok_minimum'];
    if ($sisa <= 0)        $r['_status'] = 'Stok Habis';
    elseif ($sisa <= $min) $r['_status'] = 'Tidak Aman';
    else                   $r['_status'] = 'Aman';
    $allRows[] = $r;
}

$filteredRows = $allRows;
if (!empty($filter_status)) {
    $filteredRows = array_values(array_filter($allRows, fn($r) => $r['_status'] === $filter_status));
}

$totalRow  = count($filteredRows);
$totalPage = max(1, ceil($totalRow / $perPage));
$rows      = array_slice($filteredRows, $offset, $perPage);

$tahunQ    = mysqli_query($conn, "SELECT DISTINCT tahun FROM db_apotek.v_laporan_stok_obat ORDER BY tahun DESC");
$listTahun = [];
while ($t = mysqli_fetch_assoc($tahunQ)) $listTahun[] = $t['tahun'];
$listBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

// ───────────────────────────────────────────────
// TAB 2 — LAPORAN EXP (Kadaluarsa)
// ───────────────────────────────────────────────
$search_exp        = mysqli_real_escape_string($conn, $_GET['search_exp'] ?? '');
$filter_status_exp = $_GET['status_exp'] ?? '';
$filter_tahun_exp  = $_GET['tahun_exp'] ?? '';
$filter_bulan_exp  = $_GET['bulan_exp'] ?? '';

$perPage_exp = 10;
$page_exp    = max(1, (int)($_GET['page_exp'] ?? 1));
$offset_exp  = ($page_exp - 1) * $perPage_exp;

// Filter kondisi — semua kolom dari tabel pembelian / obat
$condExp = [];
if (!empty($search_exp)) {
    $condExp[] = "(o.nama_obat LIKE '%$search_exp%')";
}
if (!empty($filter_tahun_exp)) {
    $fte = (int)$filter_tahun_exp;
    $condExp[] = "YEAR(p.expired_date) = $fte";
}
if (!empty($filter_bulan_exp)) {
    $fbe = (int)$filter_bulan_exp;
    $condExp[] = "MONTH(p.expired_date) = $fbe";
}
// Bangun whereExp — filter hanya baris dengan expired_date valid
$whereExp = "WHERE p.expired_date IS NOT NULL AND p.expired_date != '0000-00-00'";
if (!empty($condExp)) {
    $whereExp .= " AND " . implode(' AND ', $condExp);
}

// Summary exp — hitung dari baris pembelian (1 baris = 1 batch)
$summaryExpQ = mysqli_query($conn, "
    SELECT
        COUNT(DISTINCT o.id_obat) AS total_obat,
        SUM(CASE WHEN p.expired_date < '$today'
                 THEN 1 ELSE 0 END) AS total_expired,
        SUM(CASE WHEN p.expired_date >= '$today'
                  AND p.expired_date <= '$in30days'
                 THEN 1 ELSE 0 END) AS total_hampir,
        SUM(CASE WHEN p.expired_date > '$in30days'
                 THEN 1 ELSE 0 END) AS total_aman
    FROM pembelian p
    LEFT JOIN obat o ON o.id_obat = p.id_obat
    $whereExp
");
$summaryExp = mysqli_fetch_assoc($summaryExpQ);

// Detail per baris pembelian (tiap batch bisa beda expired)
$allExpQ = mysqli_query($conn, "
    SELECT
        p.id_pembelian,
        p.tanggal          AS tgl_beli,
        p.batch,
        p.expired_date,
        p.jumlah,
        o.stok,
        o.nama_obat,
        COALESCE(k.nama_kategori, '—') AS nama_kategori,
        COALESCE(s.nama_supplier, '—') AS nama_supplier,
        COALESCE(o.stok_minimum, 0)    AS stok_minimum
    FROM pembelian p
    LEFT JOIN obat     o ON o.id_obat      = p.id_obat
    LEFT JOIN kategori k ON k.id_kategori  = o.id_kategori
    LEFT JOIN supplier s ON s.id_supplier  = p.id_supplier
    $whereExp
    ORDER BY p.expired_date ASC, o.nama_obat ASC
");
$allExpRows = [];
while ($r = mysqli_fetch_assoc($allExpQ)) {
    $tgl = $r['expired_date'];
    if (!$tgl || $tgl === '0000-00-00') {
        $r['_status_exp'] = 'Tidak Diketahui';
    } elseif ($tgl < $today) {
        $r['_status_exp'] = 'Kadaluarsa';
    } elseif ($tgl <= $in30days) {
        $r['_status_exp'] = 'Hampir Exp';
    } else {
        $r['_status_exp'] = 'Aman';
    }
    $allExpRows[] = $r;
}

$filteredExpRows = $allExpRows;
if (!empty($filter_status_exp)) {
    $filteredExpRows = array_values(array_filter($allExpRows, fn($r) => $r['_status_exp'] === $filter_status_exp));
}

$totalExpRow  = count($filteredExpRows);
$totalExpPage = max(1, ceil($totalExpRow / $perPage_exp));
$expRows      = array_slice($filteredExpRows, $offset_exp, $perPage_exp);

// Daftar tahun dari expired_date di pembelian
$tahunExpQ    = mysqli_query($conn, "SELECT DISTINCT YEAR(expired_date) AS tahun FROM pembelian WHERE expired_date IS NOT NULL AND expired_date != '0000-00-00' ORDER BY tahun DESC");
$listTahunExp = [];
while ($t = mysqli_fetch_assoc($tahunExpQ)) $listTahunExp[] = $t['tahun'];
$listBulanExp = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Laporan Stok &amp; Exp — Apotek</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/laporan.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
</head>

<body>

    <nav class="topnav">
        <a href="../dashboard.php" class="sb-brand">
            <img src="../uploads/logo.png" alt="Logo Apotek" style="height:50px;" class="logo">
        </a>
        <div class="breadcrumb">
            <i class="fas fa-chevron-right"></i>
            <span class="current">Laporan Stok &amp; Exp</span>
        </div>
        <div class="topnav-right">
            <div class="user-info ddwrap" id="ddwrap" onclick="toggleDropdown()">
                <div class="user-texts">
                    <div class="uname"><?= htmlspecialchars($user['nama_user']) ?></div>
                    <div class="urole"><?= htmlspecialchars($user['role']) ?></div>
                </div>
                <div>
                    <div class="user-avatar"><?= strtoupper(substr($user['nama_user'], 0, 1)) ?></div>
                    <div class="ddmenu" id="ddmenu">
                        <span class="role-lbl">Role: <?= htmlspecialchars($user['role']) ?></span>
                        <hr>
                        <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="app-body">
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sb-sec">Core</div>
            <a class="sb-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <div class="sb-sec">Master Data</div>
            <a class="sb-link" href="../master/kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <?php if ($user['role'] != 'kasir'): ?>
                <a class="sb-link" href="../master/supplier.php"><i class="fas fa-truck"></i> Supplier</a>
            <?php endif; ?>
            <a class="sb-link" href="../master/obat.php"><i class="fas fa-pills"></i> Obat</a>
            <a class="sb-link" href="../master/member.php"><i class="fas fa-user-friends"></i> Member</a>
            <?php if ($user['role'] == 'owner'): ?>
                <div class="sb-sec">Transaksi</div>
                <a class="sb-link" href="../transaksi/pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
                <a class="sb-link" href="../transaksi/penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
                <a class="sb-link" href="../transaksi/pesanan.php"><i class="fas fa-box"></i> Pesanan</a>
                <div class="sb-sec">Laporan</div>
                <a class="sb-link" href="laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
                <a class="sb-link" href="laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
                <a class="sb-link active" href="laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
            <?php elseif ($user['role'] == 'admin'): ?>
                <div class="sb-sec">Transaksi</div>
                <a class="sb-link" href="../transaksi/pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
            <?php elseif ($user['role'] == 'kasir'): ?>
                <div class="sb-sec">Transaksi</div>
                <a class="sb-link" href="../transaksi/penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
                <a class="sb-link" href="../transaksi/pesanan.php"><i class="fas fa-box"></i> Pesanan</a>
            <?php endif; ?>
            <div class="sb-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <div class="main-content">

            <!-- ── Tab Bar (disembunyikan saat print) ── -->
            <div class="tab-bar no-print">
                <button class="tab-btn <?= $activeTab === 'stok' ? 'active' : '' ?>" onclick="switchTab('stok')">
                    <i class="fas fa-boxes"></i> Laporan Stok
                </button>
                <button class="tab-btn <?= $activeTab === 'exp' ? 'active' : '' ?>" onclick="switchTab('exp')">
                    <i class="fas fa-calendar-times"></i> Laporan Exp
                </button>
            </div>

            <!-- ══════════════════════════════════════════
             TAB PANEL — LAPORAN STOK
        ══════════════════════════════════════════ -->
            <div class="tab-panel <?= $activeTab === 'stok' ? 'active' : '' ?>" id="panel-stok">

                <!-- Print Header Stok -->
                <div class="print-header">
                    <h2>🌿 APOTEK — Laporan Stok Obat</h2>
                    <p>Dicetak: <?= date('d M Y H:i') ?></p>
                </div>

                <div class="page-header">
                    <div>
                        <h2>Laporan Stok Obat</h2>
                        <p>Rekap stok, pergerakan masuk &amp; keluar obat</p>
                    </div>
                    <div class="header-actions no-print">
                        <button class="btn-action" onclick="printTab('stok')">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="btn-action blue" onclick="exportPDFStok()">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </button>
                        <button class="btn-action" onclick="exportCSVStok()">
                            <i class="fas fa-download"></i> Export CSV
                        </button>
                    </div>
                </div>

                <!-- Summary Cards Stok -->
                <div class="summary-grid">
                    <div class="sum-card">
                        <div class="sum-icon blue"><i class="fas fa-pills"></i></div>
                        <div>
                            <div class="sum-label">Total Obat</div>
                            <div class="sum-val"><?= number_format($summary['total_obat']) ?></div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div class="sum-icon green"><i class="fas fa-arrow-circle-down"></i></div>
                        <div>
                            <div class="sum-label">Total Masuk</div>
                            <div class="sum-val" style="color:var(--green,#2d6a4f)"><?= number_format($summary['total_masuk']) ?></div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div class="sum-icon red"><i class="fas fa-arrow-circle-up"></i></div>
                        <div>
                            <div class="sum-label">Total Keluar</div>
                            <div class="sum-val" style="color:var(--red,#e63946)"><?= number_format($summary['total_keluar']) ?></div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div class="sum-icon amber"><i class="fas fa-warehouse"></i></div>
                        <div>
                            <div class="sum-label">Total Sisa Stok</div>
                            <div class="sum-val" style="color:var(--amber,#d97706)"><?= number_format($summary['total_sisa']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Tabel Stok -->
                <div class="table-card">
                    <form method="GET" id="ff">
                        <input type="hidden" name="tab" value="stok">
                        <div class="table-toolbar">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" name="search"
                                    placeholder="Cari nama obat..."
                                    value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                    onchange="document.getElementById('ff').submit()">
                            </div>
                            <div class="toolbar-right">
                                <select name="tahun" class="select-sm" onchange="document.getElementById('ff').submit()">
                                    <option value="">Semua Tahun</option>
                                    <?php foreach ($listTahun as $th): ?>
                                        <option value="<?= $th ?>" <?= $filter_tahun == $th ? 'selected' : '' ?>><?= $th ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="bulan" class="select-sm" onchange="document.getElementById('ff').submit()">
                                    <option value="">Semua Bulan</option>
                                    <?php foreach ($listBulan as $bl): ?>
                                        <option value="<?= $bl ?>" <?= $filter_bulan === $bl ? 'selected' : '' ?>><?= $bl ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="status" class="select-sm" onchange="document.getElementById('ff').submit()">
                                    <option value="">Semua Status</option>
                                    <option value="Aman" <?= $filter_status === 'Aman'       ? 'selected' : '' ?>>Aman</option>
                                    <option value="Tidak Aman" <?= $filter_status === 'Tidak Aman' ? 'selected' : '' ?>>Tidak Aman</option>
                                    <option value="Stok Habis" <?= $filter_status === 'Stok Habis' ? 'selected' : '' ?>>Stok Habis</option>
                                </select>
                                <button type="submit" class="btn-action" style="border-color:#2d6a4f;color:#2d6a4f">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <?php if ($search || $filter_tahun || $filter_bulan || $filter_status): ?>
                                    <a href="laporan_stok.php?tab=stok" class="btn-action" style="text-decoration:none">
                                        <i class="fas fa-times"></i> Reset
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>

                    <div style="overflow-x:auto">
                        <table class="dtable" id="tabel-bulanan">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tahun</th>
                                    <th>Bulan</th>
                                    <th>Nama Obat</th>
                                    <th class="right">Jumlah</th>
                                    <th class="right">Masuk</th>
                                    <th class="right">Keluar</th>
                                    <th class="right">Sisa</th>
                                    <th class="right">Stok Min</th>
                                    <th class="center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr>
                                        <td colspan="10">
                                            <div class="empty-state">
                                                <i class="fas fa-boxes"></i>
                                                <p>Tidak ada data stok ditemukan</p>
                                                <p style="font-size:12px;margin-top:4px">Coba ubah filter atau kata kunci pencarian</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php
                                    $no = ($page - 1) * $perPage + 1;
                                    foreach ($rows as $row):
                                        $st = $row['_status'];
                                        $rowClass = match ($st) {
                                            'Stok Habis' => 'row-expired',
                                            'Tidak Aman' => 'row-hampir',
                                            default      => ''
                                        };
                                        $sisaVal   = (float)$row['sisa'];
                                        $minVal    = (float)$row['stok_minimum'];
                                        $sisaColor = $sisaVal <= 0 ? '#e63946' : ($sisaVal <= $minVal ? '#d97706' : '#2d6a4f');
                                    ?>
                                        <tr class="<?= $rowClass ?>">
                                            <td class="td-mono"><?= $no++ ?></td>
                                            <td class="td-mono td-bold"><?= htmlspecialchars($row['tahun'] ?? '—') ?></td>
                                            <td>
                                                <span style="background:#f4f6f3;padding:2px 10px;border-radius:6px;font-size:12px;font-weight:600">
                                                    <?= htmlspecialchars($row['bulan'] ?? '—') ?>
                                                </span>
                                            </td>
                                            <td class="td-bold"><?= htmlspecialchars($row['nama_obat'] ?? '—') ?></td>
                                            <td class="td-right td-muted"><?= number_format($row['jumlah'] ?? 0) ?></td>
                                            <td class="td-right" style="color:#2d6a4f;font-weight:600"><?= number_format($row['masuk'] ?? 0) ?></td>
                                            <td class="td-right" style="color:#e63946;font-weight:600"><?= number_format($row['keluar'] ?? 0) ?></td>
                                            <td class="td-right td-bold">
                                                <span style="color:<?= $sisaColor ?>"><?= number_format($row['sisa'] ?? 0) ?></span>
                                            </td>
                                            <td class="td-right td-muted"><?= number_format($row['stok_minimum'] ?? 0) ?></td>
                                            <td class="td-center">
                                                <?php if ($st === 'Stok Habis'): ?>
                                                    <span class="badge-habis"><i class="fas fa-times-circle"></i> Stok Habis</span>
                                                <?php elseif ($st === 'Tidak Aman'): ?>
                                                    <span class="badge-tidak"><i class="fas fa-exclamation-triangle"></i> Tidak Aman</span>
                                                <?php else: ?>
                                                    <span class="badge-aman"><i class="fas fa-check-circle"></i> Aman</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-footer">
                        <p>Menampilkan <?= $totalRow ? (($page - 1) * $perPage + 1) : 0 ?>–<?= min($page * $perPage, $totalRow) ?> dari <?= $totalRow ?> data</p>
                        <div class="pagination no-print">
                            <button class="btn-page" <?= $page <= 1 ? 'disabled' : '' ?> onclick="goPage(<?= $page - 1 ?>, 'stok')">← Prev</button>
                            <?php for ($p = max(1, $page - 2); $p <= min($totalPage, max(1, $page - 2) + 4); $p++): ?>
                                <button class="btn-page <?= $p == $page ? 'active' : '' ?>" onclick="goPage(<?= $p ?>, 'stok')"><?= $p ?></button>
                            <?php endfor; ?>
                            <button class="btn-page" <?= $page >= $totalPage ? 'disabled' : '' ?> onclick="goPage(<?= $page + 1 ?>, 'stok')">Next →</button>
                        </div>
                    </div>
                </div><!-- /table-card stok -->

            </div><!-- /panel-stok -->


            <!-- ══════════════════════════════════════════
             TAB PANEL — LAPORAN EXP
        ══════════════════════════════════════════ -->
            <div class="tab-panel <?= $activeTab === 'exp' ? 'active' : '' ?>" id="panel-exp">

                <!-- Print Header Exp -->
                <div class="print-header">
                    <h2>🌿 APOTEK — Laporan Kadaluarsa Obat</h2>
                    <p>Dicetak: <?= date('d M Y H:i') ?></p>
                </div>

                <div class="page-header">
                    <div>
                        <h2>Laporan Kadaluarsa Obat</h2>
                        <p>Monitoring tanggal kadaluarsa &amp; status obat</p>
                    </div>
                    <div class="header-actions no-print">
                        <button class="btn-action" onclick="printTab('exp')">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="btn-action blue" onclick="exportPDFExp()">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </button>
                        <button class="btn-action" onclick="exportCSVExp()">
                            <i class="fas fa-download"></i> Export CSV
                        </button>
                    </div>
                </div>

                <!-- Summary Cards Exp -->
                <div class="summary-grid">
                    <div class="sum-card">
                        <div class="sum-icon blue"><i class="fas fa-pills"></i></div>
                        <div>
                            <div class="sum-label">Total Obat</div>
                            <div class="sum-val"><?= number_format($summaryExp['total_obat']) ?></div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div class="sum-icon red"><i class="fas fa-calendar-times"></i></div>
                        <div>
                            <div class="sum-label">Sudah Kadaluarsa</div>
                            <div class="sum-val" style="color:var(--red,#e63946)"><?= number_format($summaryExp['total_expired']) ?></div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div class="sum-icon amber"><i class="fas fa-exclamation-triangle"></i></div>
                        <div>
                            <div class="sum-label">Hampir Exp (≤30 hari)</div>
                            <div class="sum-val" style="color:var(--amber,#d97706)"><?= number_format($summaryExp['total_hampir']) ?></div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div class="sum-icon green"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="sum-label">Masih Aman</div>
                            <div class="sum-val" style="color:var(--green,#2d6a4f)"><?= number_format($summaryExp['total_aman']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Tabel Exp -->
                <div class="table-card">
                    <form method="GET" id="ff-exp">
                        <input type="hidden" name="tab" value="exp">
                        <div class="table-toolbar">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" name="search_exp"
                                    placeholder="Cari nama obat..."
                                    value="<?= htmlspecialchars($_GET['search_exp'] ?? '') ?>"
                                    onchange="document.getElementById('ff-exp').submit()">
                            </div>
                            <div class="toolbar-right">
                                <select name="tahun_exp" class="select-sm" onchange="document.getElementById('ff-exp').submit()">
                                    <option value="">Semua Tahun</option>
                                    <?php foreach ($listTahunExp as $th): ?>
                                        <option value="<?= $th ?>" <?= $filter_tahun_exp == $th ? 'selected' : '' ?>><?= $th ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="bulan_exp" class="select-sm" onchange="document.getElementById('ff-exp').submit()">
                                    <option value="">Semua Bulan</option>
                                    <?php foreach ($listBulanExp as $num => $nama): ?>
                                        <option value="<?= $num ?>" <?= $filter_bulan_exp == $num ? 'selected' : '' ?>><?= $nama ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="status_exp" class="select-sm" onchange="document.getElementById('ff-exp').submit()">
                                    <option value="">Semua Status</option>
                                    <option value="Aman" <?= $filter_status_exp === 'Aman'             ? 'selected' : '' ?>>Aman</option>
                                    <option value="Hampir Exp" <?= $filter_status_exp === 'Hampir Exp'       ? 'selected' : '' ?>>Hampir Exp</option>
                                    <option value="Kadaluarsa" <?= $filter_status_exp === 'Kadaluarsa'       ? 'selected' : '' ?>>Kadaluarsa</option>
                                    <option value="Tidak Diketahui" <?= $filter_status_exp === 'Tidak Diketahui'  ? 'selected' : '' ?>>Tidak Diketahui</option>
                                </select>
                                <button type="submit" class="btn-action" style="border-color:#2d6a4f;color:#2d6a4f">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <?php if ($search_exp || $filter_tahun_exp || $filter_bulan_exp || $filter_status_exp): ?>
                                    <a href="laporan_stok.php?tab=exp" class="btn-action" style="text-decoration:none">
                                        <i class="fas fa-times"></i> Reset
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>

                    <div style="overflow-x:auto">
                        <table class="dtable" id="tabel-exp">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Obat</th>
                                    <th>Kategori</th>
                                    <th>Supplier</th>
                                    <th>Batch</th>
                                    <th class="right">Jml Beli</th>
                                    <th class="right">Sisa Stok</th>
                                    <th class="center">Tgl Kadaluarsa</th>
                                    <th class="center">Sisa Hari</th>
                                    <th class="center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($expRows)): ?>
                                    <tr>
                                        <td colspan="10">
                                            <div class="empty-state">
                                                <i class="fas fa-calendar-times"></i>
                                                <p>Tidak ada data kadaluarsa ditemukan</p>
                                                <p style="font-size:12px;margin-top:4px">Coba ubah filter atau kata kunci pencarian</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php
                                    $noExp = ($page_exp - 1) * $perPage_exp + 1;
                                    foreach ($expRows as $row):
                                        $stExp = $row['_status_exp'];
                                        $rowClassExp = match ($stExp) {
                                            'Kadaluarsa' => 'row-expired',
                                            'Hampir Exp' => 'row-hampir',
                                            default      => ''
                                        };
                                        // Hitung sisa hari
                                        // Hitung sisa hari dari expired_date
                                        $tglKad = $row['expired_date'];
                                        $sisaHari = '—';
                                        $sisaHariColor = 'var(--muted)';
                                        if ($tglKad && $tglKad !== '0000-00-00') {
                                            $diff = (strtotime($tglKad) - strtotime($today)) / 86400;
                                            $diffInt = (int)ceil($diff);
                                            if ($diffInt < 0) {
                                                $sisaHari = abs($diffInt) . ' hari lalu';
                                                $sisaHariColor = '#e63946';
                                            } elseif ($diffInt == 0) {
                                                $sisaHari = 'Hari ini';
                                                $sisaHariColor = '#e63946';
                                            } else {
                                                $sisaHari = $diffInt . ' hari';
                                                $sisaHariColor = $diffInt <= 30 ? '#d97706' : '#2d6a4f';
                                            }
                                        }
                                        $tglDisplay = ($tglKad && $tglKad !== '0000-00-00')
                                            ? date('d M Y', strtotime($tglKad))
                                            : '—';
                                    ?>
                                        <tr class="<?= $rowClassExp ?>">
                                            <td class="td-mono"><?= $noExp++ ?></td>
                                            <td class="td-bold"><?= htmlspecialchars($row['nama_obat'] ?? '—') ?></td>
                                            <td>
                                                <span style="background:#f4f6f3;padding:2px 10px;border-radius:6px;font-size:12px;font-weight:600">
                                                    <?= htmlspecialchars($row['nama_kategori'] ?? '—') ?>
                                                </span>
                                            </td>
                                            <td class="td-muted"><?= htmlspecialchars($row['nama_supplier'] ?? '—') ?></td>
                                            <td class="td-mono"><?= htmlspecialchars($row['batch'] ?? '—') ?></td>
                                            <td class="td-right td-bold"><?= number_format($row['jumlah'] ?? 0) ?></td>
                                            <td class="td-right" style="color:<?= ((int)($row['stok'] ?? 0)) <= 0 ? '#e63946' : '#2d6a4f' ?>;font-weight:700">
                                                <?= number_format($row['stok'] ?? 0) ?>
                                            </td>
                                            <td class="td-center td-mono"><?= $tglDisplay ?></td>
                                            <td class="td-center">
                                                <span style="color:<?= $sisaHariColor ?>;font-weight:700"><?= $sisaHari ?></span>
                                            </td>
                                            <td class="td-center">
                                                <?php if ($stExp === 'Kadaluarsa'): ?>
                                                    <span class="badge-kadaluarsa"><i class="fas fa-times-circle"></i> Kadaluarsa</span>
                                                <?php elseif ($stExp === 'Hampir Exp'): ?>
                                                    <span class="badge-hampir"><i class="fas fa-exclamation-triangle"></i> Hampir Exp</span>
                                                <?php elseif ($stExp === 'Tidak Diketahui'): ?>
                                                    <span class="badge-tidak-diketahui"><i class="fas fa-question-circle"></i> Tdk Diketahui</span>
                                                <?php else: ?>
                                                    <span class="badge-aman"><i class="fas fa-check-circle"></i> Aman</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-footer">
                        <p>Menampilkan <?= $totalExpRow ? (($page_exp - 1) * $perPage_exp + 1) : 0 ?>–<?= min($page_exp * $perPage_exp, $totalExpRow) ?> dari <?= $totalExpRow ?> data</p>
                        <div class="pagination no-print">
                            <button class="btn-page" <?= $page_exp <= 1 ? 'disabled' : '' ?> onclick="goPage(<?= $page_exp - 1 ?>, 'exp')">← Prev</button>
                            <?php for ($p = max(1, $page_exp - 2); $p <= min($totalExpPage, max(1, $page_exp - 2) + 4); $p++): ?>
                                <button class="btn-page <?= $p == $page_exp ? 'active' : '' ?>" onclick="goPage(<?= $p ?>, 'exp')"><?= $p ?></button>
                            <?php endfor; ?>
                            <button class="btn-page" <?= $page_exp >= $totalExpPage ? 'disabled' : '' ?> onclick="goPage(<?= $page_exp + 1 ?>, 'exp')">Next →</button>
                        </div>
                    </div>
                </div><!-- /table-card exp -->

            </div><!-- /panel-exp -->

        </div><!-- /main-content -->
    </div><!-- /app-body -->

    <div class="toast" id="toast"></div>

    <script>
        // ── Data untuk export ──
        const allRowsStok = <?= json_encode($filteredRows) ?>;
        const summaryStok = {
            total_obat: <?= (int)$summary['total_obat'] ?>,
            total_masuk: <?= (float)($summary['total_masuk'] ?? 0) ?>,
            total_keluar: <?= (float)($summary['total_keluar'] ?? 0) ?>,
            total_sisa: <?= (float)($summary['total_sisa'] ?? 0) ?>
        };
        const allRowsExp = <?= json_encode($filteredExpRows) ?>;
        const summaryExp = {
            total_obat: <?= (int)$summaryExp['total_obat'] ?>,
            total_expired: <?= (int)$summaryExp['total_expired'] ?>,
            total_hampir: <?= (int)$summaryExp['total_hampir'] ?>,
            total_aman: <?= (int)$summaryExp['total_aman'] ?>
        };

        // ── Tab Switch ──
        function switchTab(tab) {
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('panel-' + tab).classList.add('active');
            document.querySelectorAll('.tab-btn').forEach(b => {
                if (b.getAttribute('onclick').includes("'" + tab + "'")) b.classList.add('active');
            });
            // Update URL tanpa reload
            const u = new URL(window.location.href);
            u.searchParams.set('tab', tab);
            history.replaceState(null, '', u.toString());
        }

        function goPage(p, tab) {
            const u = new URL(window.location.href);
            u.searchParams.set('tab', tab);
            if (tab === 'stok') u.searchParams.set('page', p);
            else u.searchParams.set('page_exp', p);
            window.location.href = u.toString();
        }

        // ── PRINT (per-tab, tanpa tab-bar) ──
        function printTab(tab) {
            const printHeaderEl = document.querySelector('#panel-' + tab + ' .print-header');
            const pageHeaderEl = document.querySelector('#panel-' + tab + ' .page-header');
            const summaryGridEl = document.querySelector('#panel-' + tab + ' .summary-grid');
            const tableEl = document.querySelector('#panel-' + tab + ' table');

            const printHeader = printHeaderEl?.outerHTML ?? '';
            const pageHeader = pageHeaderEl?.outerHTML ?? '';
            const summaryGrid = summaryGridEl?.outerHTML ?? '';
            const tableHTML = tableEl?.outerHTML ?? '';

            const titleMap = {
                stok: 'Laporan Stok Obat',
                exp: 'Laporan Kadaluarsa Obat'
            };

            const html = `<!DOCTYPE html>
<html lang="id"><head>
<meta charset="UTF-8">
<title>${titleMap[tab]} — Apotek</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',sans-serif;font-size:11px;color:#1a1a1a;background:#fff;padding:14mm 12mm}
.print-header{text-align:center;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid #333}
.print-header h2{font-size:16px;font-weight:700;margin-bottom:3px}
.print-header p{font-size:11px;color:#555}
.page-header{margin-bottom:12px}
.page-header h2{font-size:14px;font-weight:700}
.page-header p{font-size:11px;color:#666;margin-top:2px}
.header-actions{display:none!important}
.summary-grid{display:flex!important;flex-wrap:nowrap!important;gap:10px!important;width:100%!important;margin-bottom:14px}
.sum-card{flex:1 1 0!important;min-width:0!important;border:1px solid #ccc;border-radius:8px;padding:10px 12px;display:flex!important;align-items:center!important;gap:10px!important}
.sum-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.sum-icon.blue{background:#dbeafe;color:#1e40af}
.sum-icon.green{background:#d1fae5;color:#065f46}
.sum-icon.red{background:#fee2e2;color:#991b1b}
.sum-icon.amber{background:#fef3c7;color:#92400e}
.sum-label{font-size:10px;color:#666}
.sum-val{font-size:13px;font-weight:800;margin-top:2px}
.table-card{border:1px solid #ccc;border-radius:8px;overflow:hidden}
.table-toolbar,.table-footer,.no-print,.tab-bar,.pagination{display:none!important}
table.dtable{width:100%;border-collapse:collapse;font-size:10px}
.dtable thead th{background:#e8f5e9;color:#111;padding:6px 8px;font-size:9px;font-weight:700;border:1px solid #bbb;text-transform:uppercase}
.dtable thead th.right,.dtable thead th.center{text-align:right}
.dtable tbody td{padding:5px 8px;border:1px solid #ddd;vertical-align:middle}
.dtable tbody tr:nth-child(even) td{background:#f7fbf7}
tr.row-expired td{background:#fff0f0!important}
tr.row-hampir td{background:#fffbf0!important}
.td-right,.td-center{text-align:right}
.td-bold{font-weight:700}
.td-muted{color:#666}
.td-mono{font-family:monospace;font-size:9px;color:#666}
.badge-habis,.badge-kadaluarsa{background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-tidak,.badge-hampir{background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-aman{background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-tidak-diketahui{background:#f3f4f6;color:#6b7280;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
@page{size:A4 landscape;margin:0}
@media print{body{padding:10mm}thead{display:table-header-group}tr{page-break-inside:avoid}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
</style></head><body>
${printHeader}${pageHeader}${summaryGrid}
<div class="table-card">${tableHTML}</div>
<script>
document.querySelectorAll('.header-actions,.table-toolbar,.table-footer,.no-print,.tab-bar,.pagination').forEach(el=>el.remove());
window.onload=function(){setTimeout(function(){window.print();window.onafterprint=function(){window.close()};},700)};
<\/script></body></html>`;

            const win = window.open('', '_blank', 'width=900,height=700');
            win.document.open();
            win.document.write(html);
            win.document.close();
        }

        // ── EXPORT PDF STOK ──
        function exportPDFStok() {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: 'a4'
            });

            doc.setFontSize(16);
            doc.setFont('helvetica', 'bold');
            doc.text('APOTEK — Laporan Stok Obat', 14, 16);
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(100);
            doc.text(`Dicetak: ${new Date().toLocaleDateString('id-ID',{day:'2-digit',month:'long',year:'numeric'})}`, 14, 23);
            doc.setTextColor(0);
            doc.setFont('helvetica', 'bold');
            doc.text(`Total Obat: ${summaryStok.total_obat}`, 14, 32);
            doc.text(`Total Masuk: ${Number(summaryStok.total_masuk).toLocaleString('id-ID')}`, 80, 32);
            doc.text(`Total Keluar: ${Number(summaryStok.total_keluar).toLocaleString('id-ID')}`, 160, 32);
            doc.text(`Total Sisa: ${Number(summaryStok.total_sisa).toLocaleString('id-ID')}`, 230, 32);

            const tableData = allRowsStok.map((row, i) => [
                i + 1,
                row.tahun || '—',
                row.bulan || '—',
                row.nama_obat || '—',
                Number(row.jumlah || 0).toLocaleString('id-ID'),
                Number(row.masuk || 0).toLocaleString('id-ID'),
                Number(row.keluar || 0).toLocaleString('id-ID'),
                Number(row.sisa || 0).toLocaleString('id-ID'),
                Number(row.stok_minimum || 0).toLocaleString('id-ID'),
                row._status || 'Aman',
            ]);
            doc.autoTable({
                head: [
                    ['No', 'Tahun', 'Bulan', 'Nama Obat', 'Jumlah', 'Masuk', 'Keluar', 'Sisa', 'Stok Min', 'Status']
                ],
                body: tableData,
                startY: 38,
                styles: {
                    fontSize: 9,
                    cellPadding: 3
                },
                headStyles: {
                    fillColor: [45, 106, 79],
                    textColor: 255,
                    fontStyle: 'bold'
                },
                alternateRowStyles: {
                    fillColor: [244, 246, 243]
                },
                columnStyles: {
                    0: {
                        halign: 'center',
                        cellWidth: 10
                    },
                    1: {
                        halign: 'center',
                        cellWidth: 14
                    },
                    2: {
                        halign: 'center',
                        cellWidth: 14
                    },
                    4: {
                        halign: 'right'
                    },
                    5: {
                        halign: 'right'
                    },
                    6: {
                        halign: 'right'
                    },
                    7: {
                        halign: 'right',
                        fontStyle: 'bold'
                    },
                    8: {
                        halign: 'right'
                    },
                    9: {
                        halign: 'center'
                    }
                },
                didDrawCell: (data) => {
                    if (data.section === 'body' && data.column.index === 9) {
                        const s = data.cell.raw;
                        if (s === 'Stok Habis') data.doc.setTextColor(230, 57, 70);
                        else if (s === 'Tidak Aman') data.doc.setTextColor(217, 119, 6);
                        else data.doc.setTextColor(45, 106, 79);
                    }
                    if (data.section === 'body' && data.column.index === 5) data.doc.setTextColor(45, 106, 79);
                    if (data.section === 'body' && data.column.index === 6) data.doc.setTextColor(230, 57, 70);
                }
            });

            doc.save(`laporan_stok_${new Date().toISOString().slice(0,10)}.pdf`);
            showToast('PDF Stok berhasil didownload!');
        }

        // ── EXPORT PDF EXP ──
        function exportPDFExp() {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: 'a4'
            });

            doc.setFontSize(16);
            doc.setFont('helvetica', 'bold');
            doc.text('APOTEK — Laporan Kadaluarsa Obat', 14, 16);
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(100);
            doc.text(`Dicetak: ${new Date().toLocaleDateString('id-ID',{day:'2-digit',month:'long',year:'numeric'})}`, 14, 23);
            doc.setTextColor(0);
            doc.setFont('helvetica', 'bold');
            doc.text(`Total Obat: ${summaryExp.total_obat}`, 14, 32);
            doc.text(`Kadaluarsa: ${summaryExp.total_expired}`, 70, 32);
            doc.text(`Hampir Exp: ${summaryExp.total_hampir}`, 140, 32);
            doc.text(`Aman: ${summaryExp.total_aman}`, 210, 32);

            const todayExp = '<?= $today ?>';
            const tableData = allRowsExp.map((row, i) => {
                const tgl = row.expired_date;
                let sisaHari = '—';
                if (tgl && tgl !== '0000-00-00') {
                    const diff = Math.ceil((new Date(tgl) - new Date(todayExp)) / 86400000);
                    sisaHari = diff < 0 ? Math.abs(diff) + ' hari lalu' : diff === 0 ? 'Hari ini' : diff + ' hari';
                }
                const tglDisplay = (tgl && tgl !== '0000-00-00') ?
                    new Date(tgl).toLocaleDateString('id-ID', {
                        day: '2-digit',
                        month: 'long',
                        year: 'numeric'
                    }) :
                    '—';
                return [
                    i + 1,
                    row.nama_obat || '—',
                    row.nama_kategori || '—',
                    row.nama_supplier || '—',
                    row.batch || '—',
                    Number(row.jumlah || 0).toLocaleString('id-ID'),
                    Number(row.stok || 0).toLocaleString('id-ID'),
                    tglDisplay,
                    sisaHari,
                    row._status_exp || 'Aman',
                ];
            });
            doc.autoTable({
                head: [
                    ['No', 'Nama Obat', 'Kategori', 'Supplier', 'Batch', 'Jml Beli', 'Sisa Stok', 'Tgl Kadaluarsa', 'Sisa Hari', 'Status']
                ],
                body: tableData,
                startY: 38,
                styles: {
                    fontSize: 8,
                    cellPadding: 2.5
                },
                headStyles: {
                    fillColor: [45, 106, 79],
                    textColor: 255,
                    fontStyle: 'bold'
                },
                alternateRowStyles: {
                    fillColor: [244, 246, 243]
                },
                columnStyles: {
                    0: {
                        halign: 'center',
                        cellWidth: 9
                    },
                    5: {
                        halign: 'right'
                    },
                    6: {
                        halign: 'right'
                    },
                    7: {
                        halign: 'center'
                    },
                    8: {
                        halign: 'center'
                    },
                    9: {
                        halign: 'center'
                    }
                },
                didDrawCell: (data) => {
                    if (data.section === 'body' && data.column.index === 9) {
                        const s = data.cell.raw;
                        if (s === 'Kadaluarsa') data.doc.setTextColor(230, 57, 70);
                        else if (s === 'Hampir Exp') data.doc.setTextColor(217, 119, 6);
                        else data.doc.setTextColor(45, 106, 79);
                    }
                }
            });

            doc.save(`laporan_exp_${new Date().toISOString().slice(0,10)}.pdf`);
            showToast('PDF Exp berhasil didownload!');
        }

        // ── EXPORT CSV STOK ──
        function exportCSVStok() {
            const headers = ['No', 'Tahun', 'Bulan', 'Nama Obat', 'Jumlah', 'Masuk', 'Keluar', 'Sisa', 'Stok Min', 'Status'];
            const csvRows = allRowsStok.map((row, i) => [
                i + 1,
                row.tahun || '—',
                row.bulan || '—',
                row.nama_obat || '—',
                row.jumlah || 0,
                row.masuk || 0,
                row.keluar || 0,
                row.sisa || 0,
                row.stok_minimum || 0,
                row._status || 'Aman',
            ]);
            downloadCSV([headers, ...csvRows], `laporan_stok_${new Date().toISOString().slice(0,10)}.csv`);
            showToast('CSV Stok berhasil didownload!');
        }

        // ── EXPORT CSV EXP ──
        function exportCSVExp() {
            const todayCsv = '<?= $today ?>';
            const headers = ['No', 'Nama Obat', 'Kategori', 'Supplier', 'Batch', 'Jml Beli', 'Sisa Stok', 'Tgl Kadaluarsa', 'Sisa Hari', 'Status'];
            const csvRows = allRowsExp.map((row, i) => {
                const tgl = row.expired_date;
                let sisaHari = '—';
                if (tgl && tgl !== '0000-00-00') {
                    const diff = Math.ceil((new Date(tgl) - new Date(todayCsv)) / 86400000);
                    sisaHari = diff < 0 ? Math.abs(diff) + ' hari lalu' : diff === 0 ? 'Hari ini' : diff + ' hari';
                }
                return [
                    i + 1,
                    row.nama_obat || '—',
                    row.nama_kategori || '—',
                    row.nama_supplier || '—',
                    row.batch || '—',
                    row.jumlah || 0,
                    row.stok || 0,
                    tgl || '—',
                    sisaHari,
                    row._status_exp || 'Aman',
                ];
            });
            downloadCSV([headers, ...csvRows], `laporan_exp_${new Date().toISOString().slice(0,10)}.csv`);
            showToast('CSV Exp berhasil didownload!');
        }

        function downloadCSV(data, filename) {
            const csv = data.map(r => r.map(c => `"${c}"`).join(',')).join('\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            }));
            a.download = filename;
            a.click();
        }

        function showToast(msg, error = false) {
            const t = document.getElementById('toast');
            t.innerHTML = `<i class="fas fa-${error?'exclamation-circle':'check-circle'}"></i> ${msg}`;
            t.className = 'toast show' + (error ? ' error' : '');
            setTimeout(() => t.className = 'toast', 2800);
        }

        function toggleDropdown() {
            const m = document.getElementById('ddmenu');
            m.style.display = m.style.display === 'block' ? 'none' : 'block';
        }
        document.addEventListener('click', function(e) {
            const w = document.getElementById('ddwrap');
            if (w && !w.contains(e.target)) document.getElementById('ddmenu').style.display = 'none';
        });
    </script>
</body>

</html>