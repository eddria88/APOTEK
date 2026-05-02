<?php
session_start();
require_once "../koneksi.php";

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

$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$conditions = [];
if (!empty($tanggal_awal) && !empty($tanggal_akhir)) {
    $conditions[] = "DATE(p.tanggal) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'";
} elseif (!empty($tanggal_awal)) {
    $conditions[] = "DATE(p.tanggal) >= '$tanggal_awal'";
} elseif (!empty($tanggal_akhir)) {
    $conditions[] = "DATE(p.tanggal) <= '$tanggal_akhir'";
}
if (!empty($filter_status)) $conditions[] = "p.status_pembayaran = '$filter_status'";
if (!empty($search))        $conditions[] = "(s.nama_supplier LIKE '%$search%' OR o.nama_obat LIKE '%$search%' OR p.batch LIKE '%$search%')";
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$summaryResult = mysqli_query($conn, "
    SELECT COUNT(*) as jumlah,
           COALESCE(SUM(p.total),0) as total_semua,
           COALESCE(SUM(CASE WHEN p.status_pembayaran='Hutang' THEN p.sisa ELSE 0 END),0) as total_hutang
    FROM pembelian p
    LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
    LEFT JOIN obat o ON p.id_obat = o.id_obat
    $where
");
$summary   = mysqli_fetch_assoc($summaryResult);
$totalRow  = (int)$summary['jumlah'];
$totalPage = max(1, ceil($totalRow / $perPage));

$query = mysqli_query($conn, "
    SELECT p.*, s.nama_supplier, o.nama_obat
    FROM pembelian p
    LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
    LEFT JOIN obat o     ON p.id_obat     = o.id_obat
    $where ORDER BY p.tanggal DESC LIMIT $perPage OFFSET $offset
");
$rows = [];
while ($row = mysqli_fetch_assoc($query)) $rows[] = $row;

$queryAll = mysqli_query($conn, "
    SELECT p.*, s.nama_supplier, o.nama_obat
    FROM pembelian p
    LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
    LEFT JOIN obat o     ON p.id_obat     = o.id_obat
    $where ORDER BY p.tanggal DESC
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

    <nav class="topnav">
        <a href="../dashboard.php" class="sb-brand">
            <img src="../uploads/logo.png" alt="Logo Apotek" style="height:125px;" class="logo">
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
        <aside class="sidebar">
            <div class="sb-sec">Core</div>
            <a class="sb-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <div class="sb-sec">Master Data</div>
            <a class="sb-link" href="../master/kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <a class="sb-link" href="../master/supplier.php"><i class="fas fa-truck"></i> Supplier</a>
            <a class="sb-link" href="../master/obat.php"><i class="fas fa-pills"></i> Obat</a>
            <a class="sb-link" href="../master/member.php"><i class="fas fa-user-friends"></i> Member</a>
          
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

            <!-- Print Header (hanya muncul saat print via printRapi) -->
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

            <div class="page-header">
                <div>
                    <h2>Laporan Pembelian</h2>
                    <p>Riwayat pembelian obat dari supplier</p>
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
                            <input type="date" name="tanggal_awal"  class="date-inp" value="<?= htmlspecialchars($tanggal_awal) ?>"  title="Dari tanggal">
                            <span style="color:var(--muted);font-size:13px">s/d</span>
                            <input type="date" name="tanggal_akhir" class="date-inp" value="<?= htmlspecialchars($tanggal_akhir) ?>" title="Sampai tanggal">
                            <select name="status" class="select-sm" onchange="document.getElementById('ff').submit()">
                                <option value="">Semua Status</option>
                                <option value="Lunas"  <?= $filter_status === 'Lunas'  ? 'selected' : '' ?>>Lunas</option>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <i class="fas fa-chart-bar"></i>
                                            <p>Tidak ada data pembelian ditemukan</p>
                                            <p style="font-size:12px;margin-top:4px">Coba ubah filter atau rentang tanggal</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $no = ($page - 1) * $perPage + 1;
                                foreach ($rows as $row):
                                    $isHutang = $row['status_pembayaran'] === 'Hutang';
                                ?>
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
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <p>Menampilkan <?= $totalRow ? (($page-1)*$perPage+1) : 0 ?>–<?= min($page*$perPage, $totalRow) ?> dari <?= $totalRow ?> data</p>
                    <div class="pagination no-print">
                        <button class="btn-page" <?= $page<=1 ? 'disabled' : '' ?> onclick="goPage(<?= $page-1 ?>)">← Prev</button>
                        <?php for ($p = max(1,$page-2); $p <= min($totalPage, max(1,$page-2)+4); $p++): ?>
                            <button class="btn-page <?= $p==$page ? 'active' : '' ?>" onclick="goPage(<?= $p ?>)"><?= $p ?></button>
                        <?php endfor; ?>
                        <button class="btn-page" <?= $page>=$totalPage ? 'disabled' : '' ?> onclick="goPage(<?= $page+1 ?>)">Next →</button>
                    </div>
                </div>
            </div>

        </div>
    </div>

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
                <button class="modal-btn primary"   onclick="submitBayar()">
                    <i class="fas fa-check"></i> Konfirmasi Bayar
                </button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const allRows = <?= json_encode($allRows) ?>;
        const summaryData = {
            jumlah:       <?= (int)$summary['jumlah'] ?>,
            total_semua:  <?= (float)$summary['total_semua'] ?>,
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
        let activeBayarId = null, activeBayarSisa = 0;

        function openBayarModal(id, nama, sisa) {
            activeBayarId   = id;
            activeBayarSisa = sisa;
            document.getElementById('modal-nama-obat').textContent = nama;
            document.getElementById('modal-sisa-text').textContent = 'Rp ' + Number(sisa).toLocaleString('id-ID');
            document.getElementById('input-bayar').value = '';
            document.getElementById('modal-sisa-preview').style.display = 'none';
            document.getElementById('modal-bayar').classList.add('show');
        }

        function closeModal() { document.getElementById('modal-bayar').classList.remove('show'); }

        document.getElementById('modal-bayar').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        function previewBayar() {
            const bayar = parseFloat(document.getElementById('input-bayar').value) || 0;
            const after = activeBayarSisa - bayar;
            const el    = document.getElementById('modal-sisa-preview');
            const afEl  = document.getElementById('modal-sisa-after');
            if (bayar > 0) {
                el.style.display    = 'flex';
                afEl.textContent    = after <= 0 ? '✓ Lunas' : 'Rp ' + Number(after).toLocaleString('id-ID');
                afEl.style.color    = after <= 0 ? 'var(--green)' : 'var(--amber)';
            } else { el.style.display = 'none'; }
        }

        function submitBayar() {
            const bayar = parseFloat(document.getElementById('input-bayar').value) || 0;
            if (!bayar || bayar <= 0) { showToast('Masukkan jumlah pembayaran!', true); return; }
            const fd = new FormData();
            fd.append('ajax_bayar', '1');
            fd.append('id_pembelian', activeBayarId);
            fd.append('bayar_tambah', bayar);
            fetch(window.location.href, { method:'POST', body:fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        closeModal();
                        const row = document.getElementById('row-' + activeBayarId);
                        if (row) {
                            const sisaTd  = row.querySelector('.sisa-cell');
                            const badgeTd = row.querySelector('.badge-cell');
                            if (sisaTd) {
                                sisaTd.textContent   = 'Rp ' + Number(data.sisa_baru).toLocaleString('id-ID');
                                sisaTd.style.color      = data.status === 'Lunas' ? 'var(--muted)' : 'var(--red)';
                                sisaTd.style.fontWeight = data.status === 'Lunas' ? 'normal' : '700';
                            }
                            if (badgeTd) {
                                badgeTd.innerHTML = data.status === 'Lunas'
                                    ? '<span class="badge-lunas"><i class="fas fa-check"></i> Lunas</span>'
                                    : '<span class="badge-hutang"><i class="fas fa-exclamation"></i> Hutang</span>';
                            }
                        }
                        showToast('Pembayaran berhasil dicatat!');
                    } else {
                        showToast(data.message || 'Gagal menyimpan.', true);
                    }
                })
                .catch(() => showToast('Koneksi gagal.', true));
        }

        // ── PRINT RAPI ──
        // Membuka jendela HTML bersih (tanpa sidebar/nav) lalu auto-print
        function printRapi() {
            const printHeader = document.querySelector('.print-header')?.outerHTML ?? '';
            const pageHeader  = document.querySelector('.page-header')?.outerHTML  ?? '';
            const summaryGrid = document.querySelector('.summary-grid')?.outerHTML ?? '';
            const tableHTML   = document.querySelector('.table-card table')?.outerHTML ?? '';

            const html = `<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Pembelian — Apotek</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --green: #2d6a4f; --green-pale: #d8f3dc; --green-btn: #40916c;
            --red: #e63946;   --red-pale: #fce4e6;
            --blue: #2563eb;  --blue-pale: #dbeafe;
            --amber: #d97706; --amber-pale: #fef3c7;
            --muted: #6b7c6b; --border: #e2ebe2; --bg: #f4f6f3;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 11px; color: #1a1a1a; background: #fff;
            padding: 14mm 12mm;
        }

        /* Print header */
        .print-header {
            text-align: center; margin-bottom: 14px;
            padding-bottom: 10px; border-bottom: 2px solid #333;
        }
        .print-header h2 { font-size: 16px; font-weight: 700; margin-bottom: 3px; }
        .print-header p  { font-size: 11px; color: #555; }

        /* Page header */
        .page-header { margin-bottom: 12px; }
        .page-header h2 { font-size: 14px; font-weight: 700; }
        .page-header p  { font-size: 11px; color: #666; margin-top: 2px; }
        .header-actions { display: none !important; }

        /* Summary cards */
        .summary-grid {
            display: flex !important; flex-direction: row !important;
            flex-wrap: nowrap !important; gap: 10px !important;
            width: 100% !important; margin-bottom: 14px;
        }
        .sum-card {
            flex: 1 1 0 !important; min-width: 0 !important;
            border: 1px solid #ccc; border-radius: 8px;
            padding: 10px 12px;
            display: flex !important; align-items: center !important; gap: 10px !important;
        }
        .sum-icon {
            width: 34px; height: 34px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .sum-icon.green { background: #d1fae5; color: #065f46; }
        .sum-icon.blue  { background: #dbeafe; color: #1e40af; }
        .sum-icon.red   { background: #fee2e2; color: #991b1b; }
        .sum-icon.amber { background: #fef3c7; color: #92400e; }
        .sum-label { font-size: 10px; color: #666; }
        .sum-val   { font-size: 13px; font-weight: 800; margin-top: 2px; }

        /* Tabel */
        .table-card { border: 1px solid #ccc; border-radius: 8px; overflow: hidden; width: 100%; }
        .table-toolbar, .table-footer, .no-print,
        .btn-bayar, .aksi-cell { display: none !important; }

        table.dtable {
            width: 100%; border-collapse: collapse;
            table-layout: fixed; font-size: 10.5px;
        }
        .dtable thead th {
            background: #e8f5e9; color: #111;
            padding: 7px 8px; font-size: 10px; font-weight: 700;
            border: 1px solid #bbb; text-align: left;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .dtable thead th.right  { text-align: right; }
        .dtable thead th.center { text-align: center; }
        .dtable tbody td {
            padding: 6px 8px; border: 1px solid #ddd;
            vertical-align: middle; word-break: break-word;
        }
        .dtable tbody tr:nth-child(even) td { background: #f7fbf7; }

        .td-right  { text-align: right; }
        .td-center { text-align: center; }
        .td-bold   { font-weight: 700; }
        .td-muted  { color: #666; }
        .td-mono   { font-family: monospace; font-size: 10px; color: #666; }

        .badge-lunas  { background: #d1fae5; color: #065f46; padding: 2px 7px; border-radius: 4px; font-size: 9.5px; font-weight: 700; display: inline-block; }
        .badge-hutang { background: #fee2e2; color: #991b1b; padding: 2px 7px; border-radius: 4px; font-size: 9.5px; font-weight: 700; display: inline-block; }

        td span { background: #f0f0f0 !important; padding: 1px 5px; border-radius: 3px; font-family: monospace; font-size: 9.5px; }

        @page { size: A4 portrait; margin: 0; }
        @media print {
            body { padding: 14mm 12mm; }
            thead { display: table-header-group; }
            tr    { page-break-inside: avoid; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>
    ${printHeader}
    ${pageHeader}
    ${summaryGrid}
    <div class="table-card">
        ${tableHTML}
    </div>
    <script>
        // Hapus elemen UI yang tidak perlu
        document.querySelectorAll(
            '.header-actions, .table-toolbar, .table-footer, .btn-bayar, .aksi-cell, .no-print'
        ).forEach(el => el.remove());

        // Auto print setelah font & icon selesai load
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

        // ── Export PDF ──
        function exportPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

            doc.setFontSize(16); doc.setFont('helvetica','bold');
            doc.text('APOTEK — Laporan Pembelian', 14, 16);
            doc.setFontSize(10); doc.setFont('helvetica','normal'); doc.setTextColor(100);
            doc.text(`Periode: ${periodeLabel}`, 14, 23);
            doc.text(`Dicetak: ${new Date().toLocaleDateString('id-ID',{day:'2-digit',month:'long',year:'numeric'})}`, 14, 29);
            doc.setTextColor(0); doc.setFont('helvetica','bold');
            doc.text(`Total Transaksi: ${summaryData.jumlah}`, 14, 38);
            doc.text(`Total Pembelian: Rp ${fmt(summaryData.total_semua)}`, 80, 38);
            doc.text(`Total Hutang: Rp ${fmt(summaryData.total_hutang)}`, 180, 38);

            const tableData = allRows.map((row, i) => [
                i + 1,
                new Date(row.tanggal).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}),
                row.nama_supplier || '—', row.nama_obat || '—', row.batch || '—',
                `${Number(row.jumlah).toLocaleString('id-ID')} pcs`,
                `Rp ${fmt(row.total)}`, `Rp ${fmt(row.dibayar)}`, `Rp ${fmt(row.sisa)}`,
                row.status_pembayaran
            ]);

            doc.autoTable({
                head: [['No','Tanggal','Supplier','Obat','Batch','Jumlah','Total','Dibayar','Sisa','Status']],
                body: tableData,
                startY: 44,
                styles: { fontSize: 9, cellPadding: 3 },
                headStyles: { fillColor: [45,106,79], textColor: 255, fontStyle: 'bold' },
                alternateRowStyles: { fillColor: [244,246,243] },
                columnStyles: {
                    0:{halign:'center',cellWidth:10},
                    5:{halign:'right'}, 6:{halign:'right',fontStyle:'bold'},
                    7:{halign:'right'}, 8:{halign:'right'}, 9:{halign:'center'}
                },
                foot: [[
                    {content:'TOTAL',colSpan:6,styles:{halign:'right',fontStyle:'bold',fillColor:[216,243,220]}},
                    {content:`Rp ${fmt(summaryData.total_semua)}`,styles:{halign:'right',fontStyle:'bold',fillColor:[216,243,220]}},
                    {content:'',colSpan:3,styles:{fillColor:[216,243,220]}}
                ]],
                footStyles: { fillColor:[216,243,220], textColor:[45,106,79] }
            });

            doc.save(`laporan_pembelian_${new Date().toISOString().slice(0,10)}.pdf`);
            showToast('PDF berhasil didownload!');
        }

        // ── Export CSV ──
        function exportCSV() {
            const headers = ['No','Tanggal','Supplier','Obat','Batch','Jumlah','Total','Dibayar','Sisa','Status'];
            const csvRows = allRows.map((row, i) => [
                i+1, new Date(row.tanggal).toLocaleDateString('id-ID'),
                row.nama_supplier||'—', row.nama_obat||'—', row.batch||'—',
                row.jumlah+' pcs', row.total, row.dibayar, row.sisa, row.status_pembayaran
            ]);
            csvRows.push(['','','','','','TOTAL', summaryData.total_semua,'','','']);
            const csv = [headers,...csvRows].map(r => r.map(c=>`"${c}"`).join(',')).join('\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'}));
            a.download = `laporan_pembelian_${new Date().toISOString().slice(0,10)}.csv`;
            a.click();
            showToast('CSV berhasil didownload!');
        }

        function fmt(n) { return Number(n).toLocaleString('id-ID'); }

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