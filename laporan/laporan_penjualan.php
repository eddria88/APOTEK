<?php
session_start();
require_once "../koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SESSION['role'] != "admin" && $_SESSION['role'] != "kasir" && $_SESSION['role'] != "owner") {
    header("Location: ../dashboard.php");
    exit;
}

$username  = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user      = mysqli_fetch_assoc($queryUser);

$tanggal_awal  = $_GET['tanggal_awal']  ?? '';
$tanggal_akhir = $_GET['tanggal_akhir'] ?? '';
$search        = mysqli_real_escape_string($conn, $_GET['search'] ?? '');

$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Build WHERE
$conditions = [];
if (!empty($tanggal_awal) && !empty($tanggal_akhir)) {
    $conditions[] = "DATE(pj.tanggal) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'";
} elseif (!empty($tanggal_awal)) {
    $conditions[] = "DATE(pj.tanggal) >= '$tanggal_awal'";
} elseif (!empty($tanggal_akhir)) {
    $conditions[] = "DATE(pj.tanggal) <= '$tanggal_akhir'";
}
if (!empty($search)) {
    $conditions[] = "(m.nama_member LIKE '%$search%' OR pj.id_penjualan LIKE '%$search%')";
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Summary
$summaryResult = mysqli_query($conn, "
    SELECT COUNT(*) as jumlah,
           COALESCE(SUM(pj.total),0) as total_semua,
           COALESCE(SUM(pj.bayar),0) as total_bayar,
           COALESCE(SUM(pj.kembalian),0) as total_kembalian
    FROM penjualan pj
    LEFT JOIN member m ON pj.id_member = m.id_member
    $where
");
$summary   = mysqli_fetch_assoc($summaryResult);
$totalRow  = (int)$summary['jumlah'];
$totalPage = max(1, ceil($totalRow / $perPage));

// Fetch halaman ini
$query = mysqli_query($conn, "
    SELECT pj.*, COALESCE(m.nama_lengkap,'Umum') AS nama_member
    FROM penjualan pj
    LEFT JOIN member m ON pj.id_member = m.id_member
    $where
    ORDER BY pj.tanggal DESC
    LIMIT $perPage OFFSET $offset
");

$rows = [];
while ($row = mysqli_fetch_assoc($query)) $rows[] = $row;

// Fetch semua (untuk export)
$queryAll = mysqli_query($conn, "
    SELECT pj.*, COALESCE(m.nama_lengkap,'Umum') AS nama_member
    FROM penjualan pj
    LEFT JOIN member m ON pj.id_member = m.id_member
    $where
    ORDER BY pj.tanggal DESC
");
$allRows = [];
while ($row = mysqli_fetch_assoc($queryAll)) $allRows[] = $row;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Laporan Penjualan — Apotek</title>
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
            <span class="current">Laporan Penjualan</span>
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
            <a class="sb-link" href="kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <?php if ($user['role'] != 'kasir'): ?>
            <a class="sb-link" href="supplier.php"><i class="fas fa-truck"></i> Supplier</a>
            <?php endif; ?>
            <a class="sb-link" href="obat.php"><i class="fas fa-pills"></i> Obat</a>
            <a class="sb-link" href="member.php"><i class="fas fa-user-friends"></i> Member</a>
            <?php if ($user['role'] == 'owner'): ?>
            <div class="sb-sec">Transaksi</div>
            <a class="sb-link" href="../transaksi/pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
            <a class="sb-link" href="../transaksi/penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
            <div class="sb-sec">Laporan</div>
            <a class="sb-link active" href="../laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
            <a class="sb-link" href="../laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
            <a class="sb-link" href="../laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
            <?php elseif ($user['role'] == 'kasir'): ?>
            <div class="sb-sec">Transaksi</div>
            <a class="sb-link" href="../transaksi/penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
            <?php endif; ?>
            <div class="sb-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <div class="main-content">

            <div class="print-header">
                <h2>🌿 APOTEK — Laporan Penjualan</h2>
                <p>
                    <?php if ($tanggal_awal && $tanggal_akhir): ?>
                        Periode: <?= date('d M Y', strtotime($tanggal_awal)) ?> s/d <?= date('d M Y', strtotime($tanggal_akhir)) ?>
                    <?php else: ?>
                        Semua Periode
                    <?php endif; ?>
                    &nbsp;|&nbsp; Dicetak: <?= date('d M Y H:i') ?>
                </p>
            </div>

            <div class="page-header">
                <div>
                    <h2>Laporan Penjualan</h2>
                    <p>Riwayat transaksi penjualan obat</p>
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

            <div class="summary-grid">
                <div class="sum-card">
                    <div class="sum-icon blue"><i class="fas fa-cash-register"></i></div>
                    <div>
                        <div class="sum-label">Total Transaksi</div>
                        <div class="sum-val"><?= number_format($summary['jumlah']) ?></div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-money-bill-wave"></i></div>
                    <div>
                        <div class="sum-label">Total Penjualan</div>
                        <div class="sum-val" style="font-size:16px">Rp <?= number_format($summary['total_semua'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon amber"><i class="fas fa-hand-holding-usd"></i></div>
                    <div>
                        <div class="sum-label">Total Bayar</div>
                        <div class="sum-val" style="font-size:16px">Rp <?= number_format($summary['total_bayar'], 0, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <form method="GET" id="ff">
                    <div class="table-toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search"
                                placeholder="Cari member, ID transaksi..."
                                value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                onchange="document.getElementById('ff').submit()">
                        </div>
                        <div class="toolbar-right">
                            <input type="date" name="tanggal_awal" class="date-inp" value="<?= htmlspecialchars($tanggal_awal) ?>" title="Dari tanggal">
                            <span style="color:var(--muted);font-size:13px">s/d</span>
                            <input type="date" name="tanggal_akhir" class="date-inp" value="<?= htmlspecialchars($tanggal_akhir) ?>" title="Sampai tanggal">
                            <button type="submit" class="btn-action" style="border-color:var(--green);color:var(--green)">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <?php if ($tanggal_awal || $tanggal_akhir || $search): ?>
                                <a href="laporan_penjualan.php" class="btn-action" style="text-decoration:none">
                                    <i class="fas fa-times"></i> Reset
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>

                <div style="overflow-x:auto">
                    <table class="dtable">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Member</th>
                                <th class="right">Total</th>
                                <th class="right">Bayar</th>
                                <th class="right">Kembalian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <i class="fas fa-chart-line"></i>
                                            <p>Tidak ada data penjualan ditemukan</p>
                                            <p style="font-size:12px;margin-top:4px">Coba ubah filter atau rentang tanggal</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $no = ($page - 1) * $perPage + 1;
                                foreach ($rows as $row):
                                ?>
                                    <tr id="row-<?= $row['id_penjualan'] ?>">
                                        <td class="td-mono"><?= $no++ ?></td>
                                        <td class="td-muted"><?= date('d M Y', strtotime($row['tanggal'])) ?></td>
                                        <td><?php if (!empty($row['nama_member'])): ?>
                                                <span style="display:inline-flex;align-items:center;gap:5px;border-radius:20px;padding:2px 10px;font-size:11.5px;font-weight:700">
                                                    <i class="fas fa-user-tag"></i> <?= htmlspecialchars($row['nama_member']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:var(--muted);font-size:12px">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="td-right td-bold">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>
                                        <td class="td-right" style="color:var(--green)">Rp <?= number_format($row['bayar'], 0, ',', '.') ?></td>
                                        <td class="td-right" style="color:var(--amber)">Rp <?= number_format($row['kembalian'], 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <p>Menampilkan <?= $totalRow ? (($page - 1) * $perPage + 1) : 0 ?>–<?= min($page * $perPage, $totalRow) ?> dari <?= $totalRow ?> data</p>
                    <div class="pagination no-print">
                        <button class="btn-page" <?= $page <= 1 ? 'disabled' : '' ?> onclick="goPage(<?= $page - 1 ?>)">← Prev</button>
                        <?php for ($p = max(1, $page - 2); $p <= min($totalPage, max(1, $page - 2) + 4); $p++): ?>
                            <button class="btn-page <?= $p == $page ? 'active' : '' ?>" onclick="goPage(<?= $p ?>)"><?= $p ?></button>
                        <?php endfor; ?>
                        <button class="btn-page" <?= $page >= $totalPage ? 'disabled' : '' ?> onclick="goPage(<?= $page + 1 ?>)">Next →</button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const allRows = <?= json_encode($allRows) ?>;
        const summaryData = {
            jumlah: <?= (int)$summary['jumlah'] ?>,
            total_semua: <?= (float)$summary['total_semua'] ?>,
            total_bayar: <?= (float)$summary['total_bayar'] ?>,
            total_kembalian: <?= (float)$summary['total_kembalian'] ?>
        };
        const periodeLabel = `<?= ($tanggal_awal && $tanggal_akhir)
                                    ? date('d M Y', strtotime($tanggal_awal)) . ' s/d ' . date('d M Y', strtotime($tanggal_akhir))
                                    : 'Semua Periode' ?>`;

        function goPage(p) {
            const u = new URL(window.location.href);
            u.searchParams.set('page', p);
            window.location.href = u.toString();
        }

        // ── PRINT RAPI ──
        function printRapi() {
            const printHeader = document.querySelector('.print-header')?.outerHTML ?? '';
            const pageHeader = document.querySelector('.page-header')?.outerHTML ?? '';
            const summaryGrid = document.querySelector('.summary-grid')?.outerHTML ?? '';
            const tableHTML = document.querySelector('.table-card table')?.outerHTML ?? '';

            const html = `<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Penjualan — Apotek</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        :root {
            --green:#2d6a4f; --green-pale:#d8f3dc;
            --red:#e63946;   --red-pale:#fce4e6;
            --blue:#2563eb;  --blue-pale:#dbeafe;
            --amber:#d97706; --amber-pale:#fef3c7;
            --muted:#6b7c6b; --border:#e2ebe2; --bg:#f4f6f3;
        }
        body { font-family:'Plus Jakarta Sans',sans-serif; font-size:11px; color:#1a1a1a; background:#fff; padding:14mm 12mm; }

        .print-header { text-align:center; margin-bottom:14px; padding-bottom:10px; border-bottom:2px solid #333; }
        .print-header h2 { font-size:16px; font-weight:700; margin-bottom:3px; }
        .print-header p  { font-size:11px; color:#555; }

        .page-header { margin-bottom:12px; }
        .page-header h2 { font-size:14px; font-weight:700; }
        .page-header p  { font-size:11px; color:#666; margin-top:2px; }
        .header-actions { display:none !important; }

        .summary-grid {
            display:flex !important; flex-direction:row !important;
            flex-wrap:nowrap !important; gap:10px !important;
            width:100% !important; margin-bottom:14px;
        }
        .sum-card {
            flex:1 1 0 !important; min-width:0 !important;
            border:1px solid #ccc; border-radius:8px; padding:10px 12px;
            display:flex !important; align-items:center !important; gap:10px !important;
        }
        .sum-icon { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; }
        .sum-icon.blue  { background:#dbeafe; color:#1e40af; }
        .sum-icon.green { background:#d1fae5; color:#065f46; }
        .sum-icon.amber { background:#fef3c7; color:#92400e; }
        .sum-icon.red   { background:#fee2e2; color:#991b1b; }
        .sum-label { font-size:10px; color:#666; }
        .sum-val   { font-size:13px; font-weight:800; margin-top:2px; }

        .table-card { border:1px solid #ccc; border-radius:8px; overflow:hidden; width:100%; }
        .table-toolbar, .table-footer, .no-print { display:none !important; }

        table.dtable { width:100%; border-collapse:collapse; table-layout:fixed; font-size:10.5px; }
        .dtable thead th {
            background:#e8f5e9; color:#111; padding:7px 8px;
            font-size:10px; font-weight:700; border:1px solid #bbb;
            text-align:left; text-transform:uppercase; letter-spacing:0.3px;
        }
        .dtable thead th.right  { text-align:right; }
        .dtable thead th.center { text-align:center; }
        .dtable tbody td { padding:6px 8px; border:1px solid #ddd; vertical-align:middle; word-break:break-word; }
        .dtable tbody tr:nth-child(even) td { background:#f7fbf7; }

        .td-right  { text-align:right; }
        .td-center { text-align:center; }
        .td-bold   { font-weight:700; }
        .td-muted  { color:#666; }
        .td-mono   { font-family:monospace; font-size:10px; color:#666; }

        @page { size:A4 portrait; margin:0; }
        @media print {
            body { padding:14mm 12mm; }
            thead { display:table-header-group; }
            tr    { page-break-inside:avoid; }
            * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
        }
    </style>
</head>
<body>
    ${printHeader}
    ${pageHeader}
    ${summaryGrid}
    <div class="table-card">${tableHTML}</div>
    <script>
        document.querySelectorAll('.header-actions,.table-toolbar,.table-footer,.no-print').forEach(el=>el.remove());
        window.onload = function() {
            setTimeout(function() {
                window.print();
                window.onafterprint = function() { window.close(); };
            }, 700);
        };
    <\/script>
</body>
</html>`;

            const win = window.open('', '_blank', 'width=900,height=700');
            win.document.open();
            win.document.write(html);
            win.document.close();
        }

        // ── EXPORT PDF ──
        function exportPDF() {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'portrait',
                unit: 'mm',
                format: 'a4'
            });

            doc.setFontSize(16);
            doc.setFont('helvetica', 'bold');
            doc.text('APOTEK — Laporan Penjualan', 14, 16);
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(100);
            doc.text(`Periode: ${periodeLabel}`, 14, 23);
            doc.text(`Dicetak: ${new Date().toLocaleDateString('id-ID',{day:'2-digit',month:'long',year:'numeric'})}`, 14, 29);
            doc.setTextColor(0);
            doc.setFont('helvetica', 'bold');
            doc.text(`Total Transaksi: ${summaryData.jumlah}`, 14, 38);
            doc.text(`Total Penjualan: Rp ${fmt(summaryData.total_semua)}`, 80, 38);

            const tableData = allRows.map((row, i) => [
                i + 1,
                new Date(row.tanggal).toLocaleDateString('id-ID', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                }),
                row.nama_member || 'Umum',
                `Rp ${fmt(row.total)}`,
                `Rp ${fmt(row.bayar)}`,
                `Rp ${fmt(row.kembalian)}`
            ]);

            doc.autoTable({
                head: [
                    ['No', 'Tanggal', 'Member', 'Total', 'Bayar', 'Kembalian']
                ],
                body: tableData,
                startY: 44,
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
                        cellWidth: 12
                    },
                    3: {
                        halign: 'right',
                        fontStyle: 'bold'
                    },
                    4: {
                        halign: 'right'
                    },
                    5: {
                        halign: 'right'
                    }
                },
                foot: [
                    [{
                            content: 'TOTAL',
                            colSpan: 3,
                            styles: {
                                halign: 'right',
                                fontStyle: 'bold',
                                fillColor: [216, 243, 220]
                            }
                        },
                        {
                            content: `Rp ${fmt(summaryData.total_semua)}`,
                            styles: {
                                halign: 'right',
                                fontStyle: 'bold',
                                fillColor: [216, 243, 220]
                            }
                        },
                        {
                            content: '',
                            colSpan: 2,
                            styles: {
                                fillColor: [216, 243, 220]
                            }
                        }
                    ]
                ],
                footStyles: {
                    fillColor: [216, 243, 220],
                    textColor: [45, 106, 79]
                }
            });

            doc.save(`laporan_penjualan_${new Date().toISOString().slice(0,10)}.pdf`);
            showToast('PDF berhasil didownload!');
        }

        // ── EXPORT CSV ──
        function exportCSV() {
            const headers = ['No', 'Tanggal', 'Member', 'Total', 'Bayar', 'Kembalian'];
            const csvRows = allRows.map((row, i) => [
                i + 1,
                new Date(row.tanggal).toLocaleDateString('id-ID'),
                row.nama_member || 'Umum',
                row.total, row.bayar, row.kembalian
            ]);
            csvRows.push(['', '', 'TOTAL', summaryData.total_semua, '', '']);
            const csv = [headers, ...csvRows].map(r => r.map(c => `"${c}"`).join(',')).join('\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            }));
            a.download = `laporan_penjualan_${new Date().toISOString().slice(0,10)}.csv`;
            a.click();
            showToast('CSV berhasil didownload!');
        }

        function fmt(n) {
            return Number(n).toLocaleString('id-ID');
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