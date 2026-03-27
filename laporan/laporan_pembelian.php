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

// ── AJAX: Bayar Hutang ──
if (isset($_POST['ajax_bayar'])) {
    header('Content-Type: application/json');

    $id_pembelian = (int) $_POST['id_pembelian'];
    $bayar_tambah = (float) str_replace(',', '.', $_POST['bayar_tambah']);

    $row = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT sisa FROM pembelian WHERE id_pembelian='$id_pembelian'"
    ));

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan.']);
        exit;
    }

    $sisa_lama = (float) $row['sisa'];
    $sisa_baru = $sisa_lama - $bayar_tambah;
    if ($sisa_baru < 0) $sisa_baru = 0;

    $status_baru = $sisa_baru <= 0 ? 'Lunas' : 'Hutang';

    mysqli_query(
        $conn,
        "UPDATE pembelian
         SET dibayar = dibayar + $bayar_tambah,
             sisa    = $sisa_baru,
             status_pembayaran = '$status_baru'
         WHERE id_pembelian='$id_pembelian'"
    );

    echo json_encode([
        'success'   => true,
        'sisa_baru' => (float)$sisa_baru,
        'status'    => $status_baru
    ]);
    exit;
}

$tanggal_awal  = $_GET['tanggal_awal']  ?? '';
$tanggal_akhir = $_GET['tanggal_akhir'] ?? '';
$filter_status = $_GET['status']        ?? '';
$search        = mysqli_real_escape_string($conn, $_GET['search'] ?? '');

// Pagination
$perPage   = 10;
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * $perPage;

// Build WHERE
$conditions = [];
if (!empty($tanggal_awal) && !empty($tanggal_akhir)) {
    $conditions[] = "DATE(p.tanggal) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'";
} elseif (!empty($tanggal_awal)) {
    $conditions[] = "DATE(p.tanggal) >= '$tanggal_awal'";
} elseif (!empty($tanggal_akhir)) {
    $conditions[] = "DATE(p.tanggal) <= '$tanggal_akhir'";
}
if (!empty($filter_status)) {
    $conditions[] = "p.status_pembayaran = '$filter_status'";
}
if (!empty($search)) {
    $conditions[] = "(s.nama_supplier LIKE '%$search%' OR o.nama_obat LIKE '%$search%' OR p.batch LIKE '%$search%')";
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Summary
$summaryResult = mysqli_query($conn, "
    SELECT COUNT(*) as jumlah,
           COALESCE(SUM(p.total),0) as total_semua,
           COALESCE(SUM(CASE WHEN p.status_pembayaran='Hutang' THEN p.sisa ELSE 0 END),0) as total_hutang
    FROM pembelian p
    LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
    LEFT JOIN obat o ON p.id_obat = o.id_obat
    $where
");
$summary = mysqli_fetch_assoc($summaryResult);

$totalRow  = (int)$summary['jumlah'];
$totalPage = max(1, ceil($totalRow / $perPage));

// Fetch halaman ini
$query = mysqli_query($conn, "
    SELECT p.*, s.nama_supplier, o.nama_obat
    FROM pembelian p
    LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
    LEFT JOIN obat o     ON p.id_obat     = o.id_obat
    $where
    ORDER BY p.tanggal DESC
    LIMIT $perPage OFFSET $offset
");
$rows = [];
while ($row = mysqli_fetch_assoc($query)) $rows[] = $row;

// Fetch SEMUA data (untuk export/print)
$queryAll = mysqli_query($conn, "
    SELECT p.*, s.nama_supplier, o.nama_obat
    FROM pembelian p
    LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
    LEFT JOIN obat o     ON p.id_obat     = o.id_obat
    $where
    ORDER BY p.tanggal DESC
");
$allRows = [];
while ($row = mysqli_fetch_assoc($queryAll)) $allRows[] = $row;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Laporan Pembelian — Apotek</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/laporan.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
</head>

<body>

    <!-- TOP NAV -->
    <nav class="topnav">
        <a href="../dashboard.php" class="sb-brand">
            <i class="fas fa-capsules"></i> APOTEK
        </a>
        <div class="breadcrumb">
            <i class="fas fa-chevron-right"></i>
            <span class="current">Laporan Pembelian</span>
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
            <a class="sb-link active" href="laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
            <a class="sb-link" href="laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
            <div class="sb-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <div class="main-content">

            <!-- Print Header -->
            <div class="print-header">
                <h2>🌿 APOTEK — Laporan Pembelian</h2>
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
                    <h2>Laporan Pembelian</h2>
                    <p>Riwayat pembelian obat dari supplier</p>
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
                    <div class="sum-icon blue"><i class="fas fa-shopping-bag"></i></div>
                    <div>
                        <div class="sum-label">Total Transaksi</div>
                        <div class="sum-val"><?= number_format($summary['jumlah']) ?></div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-money-bill-wave"></i></div>
                    <div>
                        <div class="sum-label">Total Pembelian</div>
                        <div class="sum-val" style="font-size:16px">Rp <?= number_format($summary['total_semua'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon red"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div>
                        <div class="sum-label">Total Hutang</div>
                        <div class="sum-val" style="font-size:16px;color:var(--red)">Rp <?= number_format($summary['total_hutang'], 0, ',', '.') ?></div>
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
                                placeholder="Cari supplier, obat, batch..."
                                value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                onchange="document.getElementById('ff').submit()">
                        </div>
                        <div class="toolbar-right">
                            <input type="date" name="tanggal_awal" class="date-inp" value="<?= htmlspecialchars($tanggal_awal) ?>" title="Dari tanggal">
                            <span style="color:var(--muted);font-size:13px">s/d</span>
                            <input type="date" name="tanggal_akhir" class="date-inp" value="<?= htmlspecialchars($tanggal_akhir) ?>" title="Sampai tanggal">
                            <select name="status" class="select-sm" onchange="document.getElementById('ff').submit()">
                                <option value="">Semua Status</option>
                                <option value="Lunas" <?= $filter_status === 'Lunas' ? 'selected' : '' ?>>Lunas</option>
                                <option value="Hutang" <?= $filter_status === 'Hutang' ? 'selected' : '' ?>>Hutang</option>
                            </select>
                            <button type="submit" class="btn-action" style="border-color:var(--green);color:var(--green)">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <?php if ($tanggal_awal || $tanggal_akhir || $filter_status || $search): ?>
                                <a href="laporan_pembelian.php" class="btn-action" style="text-decoration:none">
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
                                <th>Supplier</th>
                                <th>Obat</th>
                                <th>Batch</th>
                                <th class="right">Jumlah</th>
                                <th class="right">Total</th>
                                <th class="right">Dibayar</th>
                                <th class="right">Sisa</th>
                                <th class="center">Status</th>
                                <th class="center no-print">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="11">
                                        <div class="empty-state">
                                            <i class="fas fa-chart-bar"></i>
                                            <p>Tidak ada data pembelian ditemukan</p>
                                            <p style="font-size:12px;margin-top:4px">Coba ubah filter atau rentang tanggal</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $no = ($page - 1) * $perPage + 1;
                                foreach ($rows as $row): $isHutang = $row['status_pembayaran'] === 'Hutang'; ?>
                                    <tr id="row-<?= $row['id_pembelian'] ?>">
                                        <td class="td-mono"><?= $no++ ?></td>
                                        <td class="td-muted"><?= date('d M Y', strtotime($row['tanggal'])) ?></td>
                                        <td class="td-bold"><?= htmlspecialchars($row['nama_supplier'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($row['nama_obat'] ?? '—') ?></td>
                                        <td><span style="background:var(--bg);padding:2px 8px;border-radius:6px;font-size:12px;font-family:monospace"><?= htmlspecialchars($row['batch'] ?? '—') ?></span></td>
                                        <td class="td-right td-muted"><?= number_format($row['jumlah']) ?> pcs</td>
                                        <td class="td-right td-bold">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>
                                        <td class="td-right" style="color:var(--green)">Rp <?= number_format($row['dibayar'], 0, ',', '.') ?></td>
                                        <td class="td-right sisa-cell" style="<?= $isHutang ? 'color:var(--red);font-weight:700' : 'color:var(--muted)' ?>">
                                            Rp <?= number_format($row['sisa'], 0, ',', '.') ?>
                                        </td>
                                        <td class="td-center badge-cell">
                                            <?php if (!$isHutang): ?>
                                                <span class="badge-lunas"><i class="fas fa-check"></i> Lunas</span>
                                            <?php else: ?>
                                                <span class="badge-hutang"><i class="fas fa-exclamation"></i> Hutang</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="td-center no-print aksi-cell">
                                            <?php if ($isHutang): ?>
                                                <button class="btn-bayar" onclick="openBayarModal(
                                        <?= $row['id_pembelian'] ?>,
                                        '<?= addslashes(htmlspecialchars($row['nama_obat'] ?? '')) ?>',
                                        <?= number_format((float)$row['sisa'], 2, '.', '') ?>
                                    )">
                                                    <i class="fas fa-money-bill-wave"></i> Bayar
                                                </button>
                                            <?php else: ?>
                                                <span style="color:var(--muted);font-size:12px">—</span>
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

        </div><!-- end main-content -->
    </div><!-- end app-body -->

    <!-- MODAL: Bayar Hutang -->
    <div class="modal-overlay" id="modal-bayar">
        <div class="modal-box">
            <div class="modal-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div class="modal-title">Bayar Hutang</div>
            <div class="modal-subtitle">Masukkan jumlah pembayaran untuk hutang ini</div>

            <div class="modal-info-row">
                <span class="label">Nama Obat</span>
                <span class="value" id="modal-nama-obat">—</span>
            </div>
            <div class="modal-info-row">
                <span class="label">Sisa Hutang</span>
                <span class="value" id="modal-sisa-text" style="color:var(--red)">—</span>
            </div>

            <div class="modal-field">
                <label>Jumlah Dibayar (Rp)</label>
                <input type="number" id="input-bayar" placeholder="Masukkan nominal..." min="1" oninput="previewBayar()">
            </div>

            <div class="sisa-preview-box" id="modal-sisa-preview">
                <span>Sisa setelah bayar</span>
                <span id="modal-sisa-after" style="font-weight:700;color:var(--amber)">Rp 0</span>
            </div>

            <div class="modal-footer">
                <button class="modal-btn secondary" onclick="closeModal()">Batal</button>
                <button class="modal-btn primary" onclick="submitBayar()">
                    <i class="fas fa-check"></i> Konfirmasi Bayar
                </button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const allRows = <?= json_encode($allRows) ?>;
        const summaryData = {
            jumlah: <?= (int)$summary['jumlah'] ?>,
            total_semua: <?= (float)$summary['total_semua'] ?>,
            total_hutang: <?= (float)$summary['total_hutang'] ?>
        };
        const periodeLabel = `<?= ($tanggal_awal && $tanggal_akhir)
                                    ? date('d M Y', strtotime($tanggal_awal)) . ' s/d ' . date('d M Y', strtotime($tanggal_akhir))
                                    : 'Semua Periode' ?>`;

        // ── Pagination ──
        function goPage(p) {
            const u = new URL(window.location.href);
            u.searchParams.set('page', p);
            window.location.href = u.toString();
        }

        // ── Bayar Hutang ──
        let activeBayarId = null;
        let activeBayarSisa = 0;

        function openBayarModal(id, nama, sisa) {
            activeBayarId = id;
            activeBayarSisa = sisa;
            document.getElementById('modal-nama-obat').textContent = nama;
            document.getElementById('modal-sisa-text').textContent = 'Rp ' + Number(sisa).toLocaleString('id-ID');
            document.getElementById('input-bayar').value = '';
            document.getElementById('modal-sisa-preview').style.display = 'none';
            document.getElementById('modal-bayar').classList.add('show');
        }

        function closeModal() {
            document.getElementById('modal-bayar').classList.remove('show');
        }

        // Klik backdrop = tutup modal
        document.getElementById('modal-bayar').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        function previewBayar() {
            const bayar = parseFloat(document.getElementById('input-bayar').value) || 0;
            const sisa_after = activeBayarSisa - bayar;
            const el = document.getElementById('modal-sisa-preview');
            const afterEl = document.getElementById('modal-sisa-after');
            if (bayar > 0) {
                el.style.display = 'flex';
                afterEl.textContent = sisa_after <= 0 ? '✓ Lunas' : 'Rp ' + Number(sisa_after).toLocaleString('id-ID');
                afterEl.style.color = sisa_after <= 0 ? 'var(--green)' : 'var(--amber)';
            } else {
                el.style.display = 'none';
            }
        }

        function submitBayar() {
            const bayar = parseFloat(document.getElementById('input-bayar').value) || 0;
            if (!bayar || bayar <= 0) {
                showToast('Masukkan jumlah pembayaran!', true);
                return;
            }

            const fd = new FormData();
            fd.append('ajax_bayar', '1');
            fd.append('id_pembelian', activeBayarId);
            fd.append('bayar_tambah', bayar);

            fetch(window.location.href, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        closeModal();

                        const row = document.getElementById('row-' + activeBayarId);
                        if (row) {
                            // Update kolom Sisa
                            const sisaTd = row.querySelector('.sisa-cell');
                            if (sisaTd) {
                                sisaTd.textContent = 'Rp ' + Number(data.sisa_baru).toLocaleString('id-ID');
                                sisaTd.style.color = data.status === 'Lunas' ? 'var(--muted)' : 'var(--red)';
                                sisaTd.style.fontWeight = data.status === 'Lunas' ? 'normal' : '700';
                            }
                            // Update badge Status
                            const badgeTd = row.querySelector('.badge-cell');
                            if (badgeTd) {
                                badgeTd.innerHTML = data.status === 'Lunas' ?
                                    '<span class="badge-lunas"><i class="fas fa-check"></i> Lunas</span>' :
                                    '<span class="badge-hutang"><i class="fas fa-exclamation"></i> Hutang</span>';
                            }
                            // Update kolom Aksi
                            const aksiTd = row.querySelector('.aksi-cell');
                            if (aksiTd && data.status === 'Lunas') {
                                aksiTd.innerHTML = '<span style="color:var(--muted);font-size:12px">—</span>';
                            }
                        }

                        showToast('Pembayaran berhasil dicatat!');

                        // Update summary card hutang
                        const newHutang = Math.max(0, summaryData.total_hutang - bayar);
                        summaryData.total_hutang = newHutang;
                        const hutangEl = document.querySelector('.sum-val[data-hutang]');
                        if (hutangEl) hutangEl.textContent = 'Rp ' + Number(newHutang).toLocaleString('id-ID');

                    } else {
                        showToast(data.message || 'Gagal menyimpan pembayaran.', true);
                    }
                })
                .catch(() => showToast('Koneksi gagal. Coba lagi.', true));
        }

        // ── Export PDF ──
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
            doc.text('APOTEK — Laporan Pembelian', 14, 16);
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(100);
            doc.text(`Periode: ${periodeLabel}`, 14, 23);
            doc.text(`Dicetak: ${new Date().toLocaleDateString('id-ID',{day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'})}`, 14, 29);
            doc.setTextColor(0);
            doc.setFont('helvetica', 'bold');
            doc.text(`Total Transaksi: ${summaryData.jumlah}`, 14, 38);
            doc.text(`Total Pembelian: Rp ${fmt(summaryData.total_semua)}`, 80, 38);
            doc.text(`Total Hutang: Rp ${fmt(summaryData.total_hutang)}`, 180, 38);

            const tableData = allRows.map((row, i) => [
                i + 1,
                new Date(row.tanggal).toLocaleDateString('id-ID', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                }),
                row.nama_supplier || '—',
                row.nama_obat || '—',
                row.batch || '—',
                `${Number(row.jumlah).toLocaleString('id-ID')} pcs`,
                `Rp ${fmt(row.total)}`,
                `Rp ${fmt(row.dibayar)}`,
                `Rp ${fmt(row.sisa)}`,
                row.status_pembayaran
            ]);

            doc.autoTable({
                head: [
                    ['No', 'Tanggal', 'Supplier', 'Obat', 'Batch', 'Jumlah', 'Total', 'Dibayar', 'Sisa', 'Status']
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
                        cellWidth: 10
                    },
                    5: {
                        halign: 'right'
                    },
                    6: {
                        halign: 'right',
                        fontStyle: 'bold'
                    },
                    7: {
                        halign: 'right'
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
                        if (data.cell.raw === 'Lunas') {
                            data.doc.setTextColor(45, 106, 79);
                        } else {
                            data.doc.setTextColor(230, 57, 70);
                        }
                    }
                },
                foot: [
                    [{
                            content: 'TOTAL',
                            colSpan: 6,
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
                            colSpan: 3,
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

            doc.save(`laporan_pembelian_${new Date().toISOString().slice(0,10)}.pdf`);
            showToast('PDF berhasil didownload!');
        }

        // ── Export CSV ──
        function exportCSV() {
            const headers = ['No', 'Tanggal', 'Supplier', 'Obat', 'Batch', 'Jumlah', 'Total', 'Dibayar', 'Sisa', 'Status'];
            const rows = allRows.map((row, i) => [
                i + 1,
                new Date(row.tanggal).toLocaleDateString('id-ID'),
                row.nama_supplier || '—',
                row.nama_obat || '—',
                row.batch || '—',
                row.jumlah + ' pcs',
                row.total, row.dibayar, row.sisa,
                row.status_pembayaran
            ]);
            rows.push(['', '', '', '', '', 'TOTAL', summaryData.total_semua, '', '', '']);
            const csv = [headers, ...rows].map(r => r.map(c => `"${c}"`).join(',')).join('\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            }));
            a.download = `laporan_pembelian_${new Date().toISOString().slice(0,10)}.csv`;
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