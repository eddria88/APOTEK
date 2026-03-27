<?php
session_start();
require_once "../koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

$username  = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user      = mysqli_fetch_assoc($queryUser);

$tanggal_awal  = $_GET['tanggal_awal']  ?? '';
$tanggal_akhir = $_GET['tanggal_akhir'] ?? '';
$search        = mysqli_real_escape_string($conn, $_GET['search'] ?? '');

// Pagination
$perPage   = 10;
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * $perPage;

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
    $conditions[] = "(pj.id_penjualan LIKE '%$search%')";
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Summary
$summaryResult = mysqli_query($conn, "
    SELECT COUNT(*) as jumlah,
           COALESCE(SUM(pj.total),0) as total_semua,
           COALESCE(SUM(pj.bayar),0) as total_bayar,
           COALESCE(SUM(pj.kembalian),0) as total_kembalian
    FROM penjualan pj
    $where
");
$summary = mysqli_fetch_assoc($summaryResult);

$totalRow  = (int)$summary['jumlah'];
$totalPage = max(1, ceil($totalRow / $perPage));

// Fetch halaman ini
$query = mysqli_query($conn, "
    SELECT pj.*
    FROM penjualan pj
    $where
    ORDER BY pj.tanggal DESC
    LIMIT $perPage OFFSET $offset
");

$rows = [];
while ($row = mysqli_fetch_assoc($query)) $rows[] = $row;

// Fetch SEMUA data (untuk export/print)
$queryAll = mysqli_query($conn, "
    SELECT pj.*
    FROM penjualan pj
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
    <!-- Navigation -->
    <nav class="topnav">
        <a href="../dashboard.php" class="sb-brand">
            <i class="fas fa-capsules"></i> APOTEK
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

        <!-- SIDEBAR -->
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
            <a class="sb-link active" href="laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
            <a class="sb-link" href="laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
            <a class="sb-link" href="laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
            <div class="sb-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <div class="main-content">

            <!-- Print Header -->
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

            <!-- PAGE HEADER -->
            <div class="page-header">
                <div>
                    <h2>Laporan Penjualan</h2>
                    <p>Riwayat transaksi penjualan obat</p>
                </div>
                <div class="header-actions no-print">
                    <button class="btn-action" onclick="window.print()">
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

            <!-- SUMMARY CARDS -->
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
                <div class="sum-card">
                    <div class="sum-icon red"><i class="fas fa-coins"></i></div>
                    <div>
                        <div class="sum-label">Total Kembalian</div>
                        <div class="sum-val" style="font-size:16px">Rp <?= number_format($summary['total_kembalian'], 0, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">

                <!-- Filter toolbar -->
                <form method="GET" id="ff">
                    <div class="table-toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search"
                                placeholder="Cari ID transaksi..."
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
                    <table class="dtable" id="main-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th class="right">Total</th>
                                <th class="right">Bayar</th>
                                <th class="right">Kembalian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <i class="fas fa-chart-line"></i>
                                            <p>Tidak ada data penjualan ditemukan</p>
                                            <p style="font-size:12px;margin-top:4px">Coba ubah filter atau rentang tanggal</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $no = ($page - 1) * $perPage + 1;
                                foreach ($rows as $row): ?>
                                    <tr>
                                        <td class="td-mono"><?= $no++ ?></td>
                                        <td class="td-muted"><?= date('d M Y', strtotime($row['tanggal'])) ?></td>
                                        <td class="td-right td-bold">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>
                                        <td class="td-right" style="color:var(--green)">Rp <?= number_format($row['bayar'], 0, ',', '.') ?></td>
                                        <td class="td-right" style="color:var(--amber)">Rp <?= number_format($row['kembalian'], 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
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

        </div><!-- end main-content -->
    </div><!-- end app-body -->

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
            doc.text(`Dicetak: ${new Date().toLocaleDateString('id-ID', {day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'})}`, 14, 29);

            doc.setTextColor(0);
            doc.setFontSize(10);
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
                `Rp ${fmt(row.total)}`,
                `Rp ${fmt(row.bayar)}`,
                `Rp ${fmt(row.kembalian)}`
            ]);

            doc.autoTable({
                head: [
                    ['No', 'Tanggal', 'Total', 'Bayar', 'Kembalian']
                ],
                body: tableData,
                startY: 44,
                styles: {
                    fontSize: 10,
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
                    2: {
                        halign: 'right',
                        fontStyle: 'bold'
                    },
                    3: {
                        halign: 'right'
                    },
                    4: {
                        halign: 'right'
                    },
                },
                foot: [
                    [{
                            content: 'TOTAL',
                            colSpan: 2,
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
                            content: `Rp ${fmt(summaryData.total_bayar)}`,
                            styles: {
                                halign: 'right',
                                fillColor: [216, 243, 220]
                            }
                        },
                        {
                            content: `Rp ${fmt(summaryData.total_kembalian)}`,
                            styles: {
                                halign: 'right',
                                fillColor: [216, 243, 220]
                            }
                        },
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
            const headers = ['No', 'Tanggal', 'Total', 'Bayar', 'Kembalian'];
            const rows = allRows.map((row, i) => [
                i + 1,
                new Date(row.tanggal).toLocaleDateString('id-ID'),
                row.total,
                row.bayar,
                row.kembalian
            ]);
            rows.push(['', 'TOTAL', summaryData.total_semua, summaryData.total_bayar, summaryData.total_kembalian]);
            const csv = [headers, ...rows].map(r => r.map(c => `"${c}"`).join(',')).join('\n');
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
            var m = document.getElementById('ddmenu');
            m.style.display = m.style.display === 'block' ? 'none' : 'block';
        }
        document.addEventListener('click', function(e) {
            var w = document.getElementById('ddwrap');
            if (w && !w.contains(e.target)) document.getElementById('ddmenu').style.display = 'none';
        });
    </script>
</body>

</html>