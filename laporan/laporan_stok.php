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

$search        = mysqli_real_escape_string($conn, $_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';

// Pagination
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$today      = date("Y-m-d");
$in30days   = date('Y-m-d', strtotime('+30 days'));

// Build WHERE
$conditions = [];
if (!empty($search)) {
    $conditions[] = "(o.nama_obat LIKE '%$search%' OR k.nama_kategori LIKE '%$search%' OR p.batch LIKE '%$search%')";
}
// status filter applied in PHP after fetch (since it's computed)
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Summary counts
$summaryQ = mysqli_query($conn, "
    SELECT
        COUNT(*) as total_batch,
        COUNT(DISTINCT p.id_obat) as total_obat,
        SUM(CASE WHEN p.expired_date <= '$today' THEN 1 ELSE 0 END) as jumlah_expired,
        SUM(CASE WHEN p.expired_date > '$today' AND p.expired_date <= '$in30days' THEN 1 ELSE 0 END) as hampir_expired,
        SUM(CASE WHEN p.expired_date > '$in30days' AND o.stok <= o.stok_minimum THEN 1 ELSE 0 END) as stok_min
    FROM pembelian p
    LEFT JOIN obat o ON p.id_obat = o.id_obat
    LEFT JOIN kategori k ON o.id_kategori = k.id_kategori
    $where
");
$summary = mysqli_fetch_assoc($summaryQ);

// Fetch all for status filter + export
$allQ = mysqli_query($conn, "
    SELECT o.nama_obat, k.nama_kategori, o.stok, o.stok_minimum,
           p.batch, p.expired_date, p.jumlah
    FROM pembelian p
    LEFT JOIN obat o ON p.id_obat = o.id_obat
    LEFT JOIN kategori k ON o.id_kategori = k.id_kategori
    $where
    ORDER BY p.expired_date ASC
");
$allRows = [];
while ($r = mysqli_fetch_assoc($allQ)) {
    // compute status
    if ($r['expired_date'] <= $today) {
        $r['_status'] = 'Expired';
    } elseif ($r['expired_date'] <= $in30days) {
        $r['_status'] = 'Hampir Expired';
    } elseif ($r['stok'] <= $r['stok_minimum']) {
        $r['_status'] = 'Stok Minimum';
    } else {
        $r['_status'] = 'Aman';
    }
    $allRows[] = $r;
}

// Apply status filter
$filteredRows = $allRows;
if (!empty($filter_status)) {
    $filteredRows = array_filter($allRows, fn($r) => $r['_status'] === $filter_status);
    $filteredRows = array_values($filteredRows);
}

$totalRow  = count($filteredRows);
$totalPage = max(1, ceil($totalRow / $perPage));
$rows      = array_slice($filteredRows, $offset, $perPage);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Laporan Stok — Apotek</title>
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

            <!-- PAGE HEADER -->
            <div class="page-header">
                <div>
                    <h2>Laporan Stok Obat</h2>
                    <p>Informasi stok, batch, dan masa berlaku obat</p>
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
                    <div class="sum-icon blue"><i class="fas fa-boxes"></i></div>
                    <div>
                        <div class="sum-label">Total Batch</div>
                        <div class="sum-val"><?= number_format($summary['total_batch']) ?></div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon red"><i class="fas fa-skull-crossbones"></i></div>
                    <div>
                        <div class="sum-label">Expired</div>
                        <div class="sum-val" style="color:var(--red)"><?= number_format($summary['jumlah_expired']) ?></div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon amber"><i class="fas fa-exclamation-triangle"></i></div>
                    <div>
                        <div class="sum-label">Hampir Expired</div>
                        <div class="sum-val" style="color:var(--amber)"><?= number_format($summary['hampir_expired']) ?></div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-layer-group"></i></div>
                    <div>
                        <div class="sum-label">Stok Minimum</div>
                        <div class="sum-val" style="color:var(--purple)"><?= number_format($summary['stok_min']) ?></div>
                    </div>
                </div>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <form method="GET" id="ff">
                    <div class="table-toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search"
                                placeholder="Cari obat, kategori, batch..."
                                value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                onchange="document.getElementById('ff').submit()">
                        </div>
                        <div class="toolbar-right">
                            <select name="status" class="select-sm" onchange="document.getElementById('ff').submit()">
                                <option value="">Semua Status</option>
                                <option value="Aman" <?= $filter_status === 'Aman' ? 'selected' : '' ?>>Aman</option>
                                <option value="Expired" <?= $filter_status === 'Expired' ? 'selected' : '' ?>>Expired</option>
                                <option value="Hampir Expired" <?= $filter_status === 'Hampir Expired' ? 'selected' : '' ?>>Hampir Expired</option>
                                <option value="Stok Minimum" <?= $filter_status === 'Stok Minimum' ? 'selected' : '' ?>>Stok Minimum</option>
                            </select>
                            <button type="submit" class="btn-action" style="border-color:var(--green);color:var(--green)">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <?php if ($search || $filter_status): ?>
                                <a href="laporan_stok.php" class="btn-action" style="text-decoration:none">
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
                                <th>Nama Obat</th>
                                <th>Kategori</th>
                                <th>Batch</th>
                                <th class="right">Jumlah Batch</th>
                                <th class="right">Stok Total</th>
                                <th>Expired Date</th>
                                <th class="center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <i class="fas fa-boxes"></i>
                                            <p>Tidak ada data stok ditemukan</p>
                                            <p style="font-size:12px;margin-top:4px">Coba ubah filter atau kata kunci pencarian</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $no = ($page - 1) * $perPage + 1;
                                foreach ($rows as $row):
                                    $st = $row['_status'];
                                    $rowClass = match ($st) {
                                        'Expired'       => 'row-expired',
                                        'Hampir Expired' => 'row-hampir',
                                        'Stok Minimum'  => 'row-minimum',
                                        default         => ''
                                    };
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="td-mono"><?= $no++ ?></td>
                                        <td class="td-bold"><?= htmlspecialchars($row['nama_obat'] ?? '—') ?></td>
                                        <td class="td-muted"><?= htmlspecialchars($row['nama_kategori'] ?? '—') ?></td>
                                        <td><span style="background:var(--bg);padding:2px 8px;border-radius:6px;font-size:12px;font-family:monospace"><?= htmlspecialchars($row['batch'] ?? '—') ?></span></td>
                                        <td class="td-right td-muted"><?= number_format($row['jumlah']) ?> pcs</td>
                                        <td class="td-right td-bold"><?= number_format($row['stok']) ?> pcs</td>
                                        <td class="td-muted"><?= $row['expired_date'] ? date('d M Y', strtotime($row['expired_date'])) : '—' ?></td>
                                        <td class="td-center">
                                            <?php if ($st === 'Expired'): ?>
                                                <span class="badge-expired"><i class="fas fa-skull-crossbones"></i> Expired</span>
                                            <?php elseif ($st === 'Hampir Expired'): ?>
                                                <span class="badge-hampir"><i class="fas fa-exclamation-triangle"></i> Hampir Expired</span>
                                            <?php elseif ($st === 'Stok Minimum'): ?>
                                                <span class="badge-minimum"><i class="fas fa-layer-group"></i> Stok Minimum</span>
                                            <?php else: ?>
                                                <span class="badge-aman"><i class="fas fa-check"></i> Aman</span>
                                            <?php endif; ?>
                                        </td>
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

        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const allRows = <?= json_encode($filteredRows) ?>;
        const summaryData = {
            total_batch: <?= (int)$summary['total_batch'] ?>,
            jumlah_expired: <?= (int)$summary['jumlah_expired'] ?>,
            hampir_expired: <?= (int)$summary['hampir_expired'] ?>,
            stok_min: <?= (int)$summary['stok_min'] ?>
        };

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
            doc.text(`Dicetak: ${new Date().toLocaleDateString('id-ID', {day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'})}`, 14, 23);

            doc.setTextColor(0);
            doc.setFont('helvetica', 'bold');
            doc.text(`Total Batch: ${summaryData.total_batch}`, 14, 32);
            doc.text(`Expired: ${summaryData.jumlah_expired}`, 70, 32);
            doc.text(`Hampir Expired: ${summaryData.hampir_expired}`, 120, 32);
            doc.text(`Stok Minimum: ${summaryData.stok_min}`, 190, 32);

            const tableData = allRows.map((row, i) => [
                i + 1,
                row.nama_obat || '—',
                row.nama_kategori || '—',
                row.batch || '—',
                `${Number(row.jumlah).toLocaleString('id-ID')} pcs`,
                `${Number(row.stok).toLocaleString('id-ID')} pcs`,
                row.expired_date ? new Date(row.expired_date).toLocaleDateString('id-ID', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                }) : '—',
                row._status
            ]);

            doc.autoTable({
                head: [
                    ['No', 'Nama Obat', 'Kategori', 'Batch', 'Jumlah Batch', 'Stok Total', 'Expired Date', 'Status']
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
                    4: {
                        halign: 'right'
                    },
                    5: {
                        halign: 'right',
                        fontStyle: 'bold'
                    },
                    7: {
                        halign: 'center'
                    }
                },
                didDrawCell: (data) => {
                    if (data.section === 'body' && data.column.index === 7) {
                        const s = data.cell.raw;
                        if (s === 'Expired') data.doc.setTextColor(230, 57, 70);
                        else if (s === 'Hampir Expired') data.doc.setTextColor(224, 123, 0);
                        else if (s === 'Stok Minimum') data.doc.setTextColor(124, 58, 237);
                        else data.doc.setTextColor(45, 106, 79);
                    }
                }
            });

            doc.save(`laporan_stok_${new Date().toISOString().slice(0,10)}.pdf`);
            showToast('PDF berhasil didownload!');
        }

        // ── EXPORT CSV ──
        function exportCSV() {
            const headers = ['No', 'Nama Obat', 'Kategori', 'Batch', 'Jumlah Batch', 'Stok Total', 'Expired Date', 'Status'];
            const rows = allRows.map((row, i) => [
                i + 1,
                row.nama_obat || '—',
                row.nama_kategori || '—',
                row.batch || '—',
                row.jumlah + ' pcs',
                row.stok + ' pcs',
                row.expired_date || '—',
                row._status
            ]);
            const csv = [headers, ...rows].map(r => r.map(c => `"${c}"`).join(',')).join('\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            }));
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