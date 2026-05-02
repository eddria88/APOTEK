<?php
session_start();
require_once "../koneksi.php";
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

if($_SESSION['role'] != "admin" && $_SESSION['role'] != "gudang" && $_SESSION['role'] != "owner"){
    header("Location: ../dashboard.php");
    exit;
}

$username  = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user      = mysqli_fetch_assoc($queryUser);

$search        = mysqli_real_escape_string($conn, $_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_tahun  = $_GET['tahun'] ?? '';
$filter_bulan  = $_GET['bulan'] ?? '';

$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$today    = date("Y-m-d");
$in30days = date('Y-m-d', strtotime('+30 days'));

// ── Kondisi WHERE untuk query utama (view + obat) ──
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

// ── Summary Cards dari view + obat ──
$summaryQ = mysqli_query($conn, "
    SELECT
        COUNT(DISTINCT v.nama_obat)   AS total_obat,
        SUM(v.masuk)                  AS total_masuk,
        SUM(v.keluar)                 AS total_keluar,
        SUM(v.sisa)                   AS total_sisa
    FROM db_apotek.v_laporan_stok_obat v
    $where
");
$summary = mysqli_fetch_assoc($summaryQ);

// ── Ambil semua data per bulan dari view JOIN obat ──
$allQ = mysqli_query($conn, "
    SELECT v.tahun, v.bulan, v.nama_obat, v.jumlah, v.masuk, v.keluar, v.sisa,
           COALESCE(o.stok_minimum, 0) AS stok_minimum
    FROM db_apotek.v_laporan_stok_obat v
    LEFT JOIN obat o ON o.nama_obat = v.nama_obat
    $where
    ORDER BY v.tahun DESC,
             FIELD(v.bulan,'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des') DESC,
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

// ── Filter status di PHP ──
$filteredRows = $allRows;
if (!empty($filter_status)) {
    $filteredRows = array_values(array_filter($allRows, fn($r) => $r['_status'] === $filter_status));
}

$totalRow  = count($filteredRows);
$totalPage = max(1, ceil($totalRow / $perPage));
$rows      = array_slice($filteredRows, $offset, $perPage);

// ── Daftar tahun & bulan untuk filter ──
$tahunQ    = mysqli_query($conn, "SELECT DISTINCT tahun FROM db_apotek.v_laporan_stok_obat ORDER BY tahun DESC");
$listTahun = [];
while ($t = mysqli_fetch_assoc($tahunQ)) $listTahun[] = $t['tahun'];
$listBulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Laporan Stok Obat — Apotek</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/laporan.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <style>
    </style>
</head>
<body>

<nav class="topnav">
    <a href="../dashboard.php" class="sb-brand">
        <img src="../uploads/logo.png" alt="Logo Apotek" style="height:50px;" class="logo">
    </a>
    <div class="breadcrumb">
        <i class="fas fa-chevron-right"></i>
        <span class="current">Laporan Stok</span>
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
    <aside class="sidebar">
        <div class="sb-sec">Core</div>
        <a class="sb-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <div class="sb-sec">Master Data</div>
        <a class="sb-link" href="../master/kategori.php"><i class="fas fa-tags"></i> Kategori</a>
        <a class="sb-link" href="../master/supplier.php"><i class="fas fa-truck"></i> Supplier</a>
        <a class="sb-link" href="../master/obat.php"><i class="fas fa-pills"></i> Obat</a>
        <a class="sb-link" href="../master/member.php"><i class="fas fa-user-friends"></i> Member</a>
        <div class="sb-sec">Transaksi</div>
        <a class="sb-link" href="../transaksi/pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
        <a class="sb-link" href="../transaksi/penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
        <div class="sb-sec">Laporan</div>
        <a class="sb-link" href="laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
        <a class="sb-link" href="laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
        <a class="sb-link active" href="laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
        <div class="sb-footer">
            <div class="small">Masuk sebagai</div>
            <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
        </div>
    </aside>

    <div class="main-content">

        <!-- Print Header -->
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
                <button class="btn-action" onclick="printRapi()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="btn-action blue" onclick="exportPDF()">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
                <button class="btn-action" onclick="exportCSV()">
                    <i class="fas fa-download"></i> Export CSV
                </button>
            </div>
        </div>

        <!-- 4 Summary Cards -->
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

        <!-- Tabel Bulanan -->
        <div class="table-card">
                <form method="GET" id="ff">
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
                                <option value="Aman"       <?= $filter_status === 'Aman'       ? 'selected' : '' ?>>Aman</option>
                                <option value="Tidak Aman" <?= $filter_status === 'Tidak Aman' ? 'selected' : '' ?>>Tidak Aman</option>
                                <option value="Stok Habis" <?= $filter_status === 'Stok Habis' ? 'selected' : '' ?>>Stok Habis</option>
                            </select>
                            <button type="submit" class="btn-action" style="border-color:#2d6a4f;color:#2d6a4f">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <?php if ($search || $filter_tahun || $filter_bulan || $filter_status): ?>
                                <a href="laporan_stok.php" class="btn-action" style="text-decoration:none">
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
                                    $rowClass = match($st) {
                                        'Stok Habis' => 'row-expired',
                                        'Tidak Aman' => 'row-hampir',
                                        default      => ''
                                    };
                                    $sisaVal  = (float)$row['sisa'];
                                    $minVal   = (float)$row['stok_minimum'];
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

                </div>

                <div class="table-footer">
                    <p>Menampilkan <?= $totalRow ? (($page-1)*$perPage+1) : 0 ?>–<?= min($page*$perPage,$totalRow) ?> dari <?= $totalRow ?> data</p>
                    <div class="pagination no-print">
                        <button class="btn-page" <?= $page<=1?'disabled':'' ?> onclick="goPage(<?= $page-1 ?>)">← Prev</button>
                        <?php for ($p = max(1,$page-2); $p <= min($totalPage,max(1,$page-2)+4); $p++): ?>
                            <button class="btn-page <?= $p==$page?'active':'' ?>" onclick="goPage(<?= $p ?>)"><?= $p ?></button>
                        <?php endfor; ?>
                        <button class="btn-page" <?= $page>=$totalPage?'disabled':'' ?> onclick="goPage(<?= $page+1 ?>)">Next →</button>
                    </div>
                </div>
            </div>

    </div><!-- /main-content -->
</div><!-- /app-body -->

<div class="toast" id="toast"></div>

<script>
    // ── Data untuk export ──
    const allRowsBulanan = <?= json_encode($filteredRows) ?>;
    const summaryData = {
        total_obat:   <?= (int)$summary['total_obat'] ?>,
        total_masuk:  <?= (float)($summary['total_masuk'] ?? 0) ?>,
        total_keluar: <?= (float)($summary['total_keluar'] ?? 0) ?>,
        total_sisa:   <?= (float)($summary['total_sisa'] ?? 0) ?>
    };

    function goPage(p) {
        const u = new URL(window.location.href);
        u.searchParams.set('page', p);
        window.location.href = u.toString();
    }

    // ── PRINT ──
    function printRapi() {
        const printHeader = document.querySelector('.print-header')?.outerHTML ?? '';
        const pageHeader  = document.querySelector('.page-header')?.outerHTML  ?? '';
        const summaryGrid = document.querySelector('.summary-grid')?.outerHTML ?? '';
        const tableHTML   = document.querySelector('.table-card table')?.outerHTML ?? '';

        const html = `<!DOCTYPE html>
<html lang="id"><head>
<meta charset="UTF-8">
<title>Laporan Stok — Apotek</title>
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
.table-toolbar,.table-footer,.no-print,.tab-bar{display:none!important}
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
.badge-habis{background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700}
.badge-tidak{background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700}
.badge-aman{background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700}
@page{size:A4 landscape;margin:0}
@media print{body{padding:10mm}thead{display:table-header-group}tr{page-break-inside:avoid}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
</style></head><body>
${printHeader}${pageHeader}${summaryGrid}
<div class="table-card">${tableHTML}</div>
<script>
document.querySelectorAll('.header-actions,.table-toolbar,.table-footer,.no-print,.tab-bar').forEach(el=>el.remove());
window.onload=function(){setTimeout(function(){window.print();window.onafterprint=function(){window.close()};},700)};
<\/script></body></html>`;

        const win = window.open('', '_blank', 'width=900,height=700');
        win.document.open();
        win.document.write(html);
        win.document.close();
    }

    // ── EXPORT PDF ──
    function exportPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation:'landscape', unit:'mm', format:'a4' });

        doc.setFontSize(16); doc.setFont('helvetica','bold');
        doc.text('APOTEK — Laporan Stok Obat', 14, 16);
        doc.setFontSize(10); doc.setFont('helvetica','normal'); doc.setTextColor(100);
        doc.text(`Dicetak: ${new Date().toLocaleDateString('id-ID',{day:'2-digit',month:'long',year:'numeric'})}`, 14, 23);
        doc.setTextColor(0); doc.setFont('helvetica','bold');
        doc.text(`Total Obat: ${summaryData.total_obat}`, 14, 32);
        doc.text(`Total Masuk: ${Number(summaryData.total_masuk).toLocaleString('id-ID')}`, 80, 32);
        doc.text(`Total Keluar: ${Number(summaryData.total_keluar).toLocaleString('id-ID')}`, 160, 32);
        doc.text(`Total Sisa: ${Number(summaryData.total_sisa).toLocaleString('id-ID')}`, 230, 32);

        const tableData = allRowsBulanan.map((row, i) => [
            i + 1,
            row.tahun    || '—',
            row.bulan    || '—',
            row.nama_obat|| '—',
            Number(row.jumlah  || 0).toLocaleString('id-ID'),
            Number(row.masuk   || 0).toLocaleString('id-ID'),
            Number(row.keluar  || 0).toLocaleString('id-ID'),
            Number(row.sisa    || 0).toLocaleString('id-ID'),
            Number(row.stok_minimum || 0).toLocaleString('id-ID'),
            row._status  || 'Aman',
        ]);
        doc.autoTable({
            head: [['No','Tahun','Bulan','Nama Obat','Jumlah','Masuk','Keluar','Sisa','Stok Min','Status']],
            body: tableData, startY: 38,
            styles: { fontSize:9, cellPadding:3 },
            headStyles: { fillColor:[45,106,79], textColor:255, fontStyle:'bold' },
            alternateRowStyles: { fillColor:[244,246,243] },
            columnStyles: {
                0:{halign:'center',cellWidth:10}, 1:{halign:'center',cellWidth:14},
                2:{halign:'center',cellWidth:14}, 4:{halign:'right'},
                5:{halign:'right'}, 6:{halign:'right'},
                7:{halign:'right',fontStyle:'bold'}, 8:{halign:'right'}, 9:{halign:'center'}
            },
            didDrawCell: (data) => {
                if (data.section==='body' && data.column.index===9) {
                    const s = data.cell.raw;
                    if (s==='Stok Habis')      data.doc.setTextColor(230,57,70);
                    else if (s==='Tidak Aman') data.doc.setTextColor(217,119,6);
                    else                       data.doc.setTextColor(45,106,79);
                }
                if (data.section==='body' && data.column.index===5) data.doc.setTextColor(45,106,79);
                if (data.section==='body' && data.column.index===6) data.doc.setTextColor(230,57,70);
            }
        });

        doc.save(`laporan_stok_${new Date().toISOString().slice(0,10)}.pdf`);
        showToast('PDF berhasil didownload!');
    }

    // ── EXPORT CSV ──
    function exportCSV() {
        const headers = ['No','Tahun','Bulan','Nama Obat','Jumlah','Masuk','Keluar','Sisa','Stok Min','Status'];
        const csvRows = allRowsBulanan.map((row, i) => [
            i + 1,
            row.tahun        || '—',
            row.bulan        || '—',
            row.nama_obat    || '—',
            row.jumlah       || 0,
            row.masuk        || 0,
            row.keluar       || 0,
            row.sisa         || 0,
            row.stok_minimum || 0,
            row._status      || 'Aman',
        ]);
        const csv = [headers, ...csvRows].map(r => r.map(c => `"${c}"`).join(',')).join('\n');
        const a   = document.createElement('a');
        a.href    = URL.createObjectURL(new Blob([csv], { type:'text/csv;charset=utf-8;' }));
        a.download = `laporan_stok_${new Date().toISOString().slice(0,10)}.csv`;
        a.click();
        showToast('CSV berhasil didownload!');
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