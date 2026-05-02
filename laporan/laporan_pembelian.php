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

    if ($bayar_tambah <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jumlah pembayaran harus lebih dari 0.']);
        exit;
    }
    if ($bayar_tambah > $sisa_lama) {
        echo json_encode([
            'success' => false,
            'message' => 'Pembayaran melebihi sisa hutang (Rp ' . number_format($sisa_lama, 0, ',', '.') . ').'
        ]);
        exit;
    }

    $sisa_baru   = $sisa_lama - $bayar_tambah;
    if ($sisa_baru < 0) $sisa_baru = 0;
    $status_baru = $sisa_baru <= 0 ? 'Lunas' : 'Hutang';

    mysqli_query($conn,
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

// ── Filter & pagination ──
$tanggal_awal  = mysqli_real_escape_string($conn, $_GET['tanggal_awal']  ?? '');
$tanggal_akhir = mysqli_real_escape_string($conn, $_GET['tanggal_akhir'] ?? '');
$filter_status = mysqli_real_escape_string($conn, $_GET['status']        ?? '');
$search        = mysqli_real_escape_string($conn, $_GET['search']        ?? '');

function isValidDate($d) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}
if (!isValidDate($tanggal_awal))  $tanggal_awal  = '';
if (!isValidDate($tanggal_akhir)) $tanggal_akhir = '';

$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$conditions = [];
if ($tanggal_awal && $tanggal_akhir)
    $conditions[] = "DATE(p.tanggal) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'";
elseif ($tanggal_awal)
    $conditions[] = "DATE(p.tanggal) >= '$tanggal_awal'";
elseif ($tanggal_akhir)
    $conditions[] = "DATE(p.tanggal) <= '$tanggal_akhir'";
if ($filter_status) $conditions[] = "p.status_pembayaran = '$filter_status'";
if ($search)        $conditions[] = "(s.nama_supplier LIKE '%$search%' OR o.nama_obat LIKE '%$search%' OR p.batch LIKE '%$search%')";
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$summaryResult = mysqli_query($conn, "
    SELECT COUNT(*) as jumlah,
           COALESCE(SUM(p.total),0) as total_semua,
           COALESCE(SUM(CASE WHEN p.status_pembayaran='Hutang' THEN p.sisa ELSE 0 END),0) as total_hutang
    FROM pembelian p
    LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
    LEFT JOIN obat o     ON p.id_obat     = o.id_obat
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

    <style>
        /* ── Kolom batch: polos, tidak berwarna, tidak bisa 2 baris ───────── */
        .dtable th.col-batch,
        .dtable td.col-batch { min-width:148px; white-space:nowrap; }
        .batch-text { font-family:'DM Mono',monospace; font-size:12.5px; white-space:nowrap; letter-spacing:0.4px; }

        /* ── Kolom uang: tidak bisa 2 baris ─────────────────────────────────── */
        .dtable td.td-right,
        .dtable th.right    { min-width:100px; white-space:nowrap; }

        /* ── Scroll horizontal ───────────────────────────────────────────────── */
        .table-scroll { overflow-x:auto; }
        .dtable       { min-width:900px; }

        /* ── Tanggal 2-baris & dibayar/sisa label kecil ─────────────────────── */
        .tgl-main  { display:block; font-weight:700; font-size:13.5px; line-height:1.2; }
        .tgl-sub   { display:block; font-size:11px;  color:var(--muted); line-height:1.2; }

        /* ── Print tambahan — melengkapi @media print di laporan.css ────────── */
        @media print {
            @page { size:A4 landscape; margin:14mm 12mm; }

            /* Kolom batch tetap nowrap di print */
            .dtable th.col-batch,
            .dtable td.col-batch { white-space:nowrap; }
            .batch-text          { font-family:monospace; font-size:9px; white-space:nowrap; }

            /* Tanggal 2-baris */
            .tgl-main  { font-size:9.5px !important; }
            .tgl-sub   { font-size:8px   !important; }

            /* Dibayar / sisa label */
            .amt-main  { font-size:9px   !important; }
            .amt-label { font-size:7.5px !important; }

            /* Sembunyikan wrapper scroll agar tabel full-width di print */
            .table-scroll { overflow:visible !important; }
            .dtable       { min-width:0 !important; width:100% !important; table-layout:auto !important; }
        }
    </style>
</head>
<body>

    <nav class="topnav">
        <a href="../dashboard.php" class="sb-brand">
            <img src="../uploads/logo.png" alt="Logo Apotek" style="height:50px;" class="logo">
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

            <!-- Summary cards -->
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

            <!-- Table card -->
            <div class="table-card">
                <form method="GET" id="ff">
                    <div class="table-toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search"
                                placeholder="Cari supplier, obat, batch..."
                                id="searchInput"
                                value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                oninput="scheduleSearch()"
                                onkeydown="if(event.key==='Enter'){event.preventDefault();submitSearch();}">
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

                <!-- Wrapper scroll horizontal -->
                <div class="table-scroll">
                    <table class="dtable">
                        <thead>
                            <tr>
                                <th style="width:44px">No</th>
                                <th style="width:100px">Tanggal</th>
                                <th style="min-width:120px">Supplier</th>
                                <th style="min-width:130px">Obat</th>
                                <th class="col-batch" style="min-width:155px">Batch / No. Lot</th>
                                <th class="right" style="width:80px">Jumlah</th>
                                <th class="right" style="min-width:95px">Total</th>
                                <th class="right" style="min-width:90px">Dibayar</th>
                                <th class="right" style="min-width:90px">Sisa</th>
                                <th class="center" style="width:90px">Status</th>
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
                                        <td class="td-mono" style="text-align:center"><?= $no++ ?></td>

                                        <!-- Tanggal 2 baris -->
                                        <td style="white-space:nowrap">
                                            <span class="tgl-main"><?= date('d', strtotime($row['tanggal'])) ?></span>
                                            <span class="tgl-sub"><?= date('M Y', strtotime($row['tanggal'])) ?></span>
                                        </td>

                                        <td class="td-bold"><?= htmlspecialchars($row['nama_supplier'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($row['nama_obat'] ?? '—') ?></td>

                                        <!-- Batch: teks polos, tidak berwarna, tidak bisa 2 baris -->
                                        <td class="col-batch">
                                            <?php if (!empty($row['batch'])): ?>
                                                <span class="batch-text"><?= htmlspecialchars($row['batch']) ?></span>
                                            <?php else: ?>
                                                <span style="color:var(--muted);font-size:12px">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="td-right td-muted"><?= number_format($row['jumlah']) ?> pcs</td>
                                        <td class="td-right td-bold">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>

                                        <!-- Dibayar 2 baris -->
                                        <td class="td-right" style="white-space:nowrap">
                                            <span style="color:var(--green)">Rp <?= number_format($row['dibayar'], 0, ',', '.') ?></span>
                                        </td>

                                        <!-- Sisa 2 baris -->
                                        <td class="td-right sisa-cell" style="white-space:nowrap">
                                            <span style="<?= $isHutang ? 'color:var(--red);font-weight:700' : 'color:var(--muted)' ?>">Rp <?= number_format($row['sisa'], 0, ',', '.') ?></span>
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
                </div><!-- /table-scroll -->

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
            </div><!-- /table-card -->

        </div><!-- /main-content -->
    </div><!-- /app-body -->

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
                <small id="bayar-hint" style="color:var(--muted);font-size:11.5px;margin-top:4px;display:block">
                    Maksimal: <strong id="bayar-hint-max">—</strong>
                </small>
                <div id="bayar-error" style="display:none;margin-top:6px;padding:7px 10px;background:#fef2f2;
                     border:1px solid #fca5a5;border-radius:7px;color:#dc2626;font-size:12px;font-weight:600">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="bayar-error-msg"></span>
                </div>
            </div>
            <div class="sisa-preview-box" id="modal-sisa-preview" style="display:none">
                <span>Sisa setelah bayar</span>
                <span id="modal-sisa-after" style="font-weight:700;color:var(--amber)">Rp 0</span>
            </div>
            <div class="modal-footer">
                <button class="modal-btn secondary" onclick="closeModal()">Batal</button>
                <button class="modal-btn primary" id="btn-konfirmasi-bayar" onclick="submitBayar()">
                    <i class="fas fa-check"></i> Konfirmasi Bayar
                </button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const allRows = <?= json_encode($allRows) ?>;
        const summaryData = {
            jumlah       : <?= (int)$summary['jumlah'] ?>,
            total_semua  : <?= (float)$summary['total_semua'] ?>,
            total_hutang : <?= (float)$summary['total_hutang'] ?>
        };
        const periodeLabel = `<?= ($tanggal_awal && $tanggal_akhir)
            ? date('d M Y', strtotime($tanggal_awal)) . ' s/d ' . date('d M Y', strtotime($tanggal_akhir))
            : 'Semua Periode' ?>`;

        // ── Pagination (pertahankan semua filter) ────────────────────────────
        function goPage(p) {
            const u = new URL(window.location.href);
            u.searchParams.set('page', p);
            window.location.href = u.toString();
        }

        // ── Search debounce ──────────────────────────────────────────────────
        let searchTimer = null;
        function scheduleSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(submitSearch, 500);
        }
        function submitSearch() {
            clearTimeout(searchTimer);
            const form = document.getElementById('ff');
            let pi = form.querySelector('input[name="page"]');
            if (!pi) { pi = document.createElement('input'); pi.type = 'hidden'; pi.name = 'page'; form.appendChild(pi); }
            pi.value = 1;
            form.submit();
        }

        // ── Bayar Hutang ─────────────────────────────────────────────────────
        let activeBayarId = null, activeBayarSisa = 0;

        function openBayarModal(id, nama, sisa) {
            activeBayarId   = id;
            activeBayarSisa = sisa;
            document.getElementById('modal-nama-obat').textContent   = nama;
            document.getElementById('modal-sisa-text').textContent   = formatRp(sisa);
            document.getElementById('bayar-hint-max').textContent    = formatRp(sisa);
            document.getElementById('input-bayar').value             = '';
            document.getElementById('input-bayar').max               = sisa;
            document.getElementById('bayar-error').style.display     = 'none';
            document.getElementById('modal-sisa-preview').style.display = 'none';
            document.getElementById('btn-konfirmasi-bayar').disabled = false;
            document.getElementById('btn-konfirmasi-bayar').style.opacity = '1';
            document.getElementById('modal-bayar').classList.add('show');
        }

        function closeModal() { document.getElementById('modal-bayar').classList.remove('show'); }

        document.getElementById('modal-bayar').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        function previewBayar() {
            const bayar    = parseFloat(document.getElementById('input-bayar').value) || 0;
            const errorEl  = document.getElementById('bayar-error');
            const errorMsg = document.getElementById('bayar-error-msg');
            const btnEl    = document.getElementById('btn-konfirmasi-bayar');
            const previewEl= document.getElementById('modal-sisa-preview');

            if (bayar > activeBayarSisa) {
                errorMsg.textContent    = `Melebihi sisa hutang! Maks. ${formatRp(activeBayarSisa)}.`;
                errorEl.style.display   = 'block';
                btnEl.disabled          = true;
                btnEl.style.opacity     = '0.5';
                btnEl.style.cursor      = 'not-allowed';
                previewEl.style.display = 'none';
                return;
            }
            errorEl.style.display = 'none';
            btnEl.disabled        = false;
            btnEl.style.opacity   = '1';
            btnEl.style.cursor    = 'pointer';

            if (bayar > 0) {
                const after = activeBayarSisa - bayar;
                previewEl.style.display = 'flex';
                const afEl = document.getElementById('modal-sisa-after');
                afEl.textContent = after <= 0 ? '✓ Lunas' : formatRp(after);
                afEl.style.color = after <= 0 ? 'var(--green)' : 'var(--amber)';
            } else {
                previewEl.style.display = 'none';
            }
        }

        function submitBayar() {
            const bayar = parseFloat(document.getElementById('input-bayar').value) || 0;
            if (!bayar || bayar <= 0) { showToast('Masukkan jumlah pembayaran!', true); return; }
            if (bayar > activeBayarSisa) {
                showToast(`Pembayaran tidak boleh melebihi sisa hutang (${formatRp(activeBayarSisa)})!`, true);
                return;
            }

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
                                sisaTd.textContent      = formatRp(data.sisa_baru);
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

        // ── PRINT RAPI — cukup window.print(), laporan.css @media print yang handle ──
        function printRapi() {
            window.print();
        }

        // ── Export PDF ────────────────────────────────────────────────────────
        function exportPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ orientation:'landscape', unit:'mm', format:'a4' });

            doc.setFontSize(16); doc.setFont('helvetica','bold');
            doc.text('APOTEK — Laporan Pembelian', 14, 16);
            doc.setFontSize(10); doc.setFont('helvetica','normal'); doc.setTextColor(100);
            doc.text(`Periode: ${periodeLabel}`, 14, 23);
            doc.text(`Dicetak: ${new Date().toLocaleDateString('id-ID',{day:'2-digit',month:'long',year:'numeric'})}`, 14, 29);
            doc.setTextColor(0); doc.setFont('helvetica','bold');
            doc.text(`Total Transaksi: ${summaryData.jumlah}`, 14, 38);
            doc.text(`Total Pembelian: Rp ${fmt(summaryData.total_semua)}`, 90, 38);
            doc.text(`Total Hutang: Rp ${fmt(summaryData.total_hutang)}`, 190, 38);

            const tableData = allRows.map((row, i) => [
                i + 1,
                new Date(row.tanggal).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}),
                row.nama_supplier || '—',
                row.nama_obat     || '—',
                row.batch         || '—',
                `${Number(row.jumlah).toLocaleString('id-ID')} pcs`,
                `Rp ${fmt(row.total)}`,
                `Rp ${fmt(row.dibayar)}`,
                `Rp ${fmt(row.sisa)}`,
                row.status_pembayaran
            ]);

            doc.autoTable({
                head: [['No','Tanggal','Supplier','Obat','Batch / No.Lot','Jumlah','Total','Dibayar','Sisa','Status']],
                body: tableData,
                startY: 44,
                styles        : { fontSize:8.5, cellPadding:3 },
                headStyles    : { fillColor:[45,106,79], textColor:255, fontStyle:'bold' },
                alternateRowStyles: { fillColor:[244,246,243] },
                columnStyles  : {
                    0: { halign:'center', cellWidth:10 },
                    4: { font:'courier', fontSize:8 },   /* Batch pakai monospace */
                    5: { halign:'right' },
                    6: { halign:'right', fontStyle:'bold' },
                    7: { halign:'right' },
                    8: { halign:'right' },
                    9: { halign:'center' }
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

        // ── Export CSV ────────────────────────────────────────────────────────
        function exportCSV() {
            const headers = ['No','Tanggal','Supplier','Obat','Batch','Jumlah','Total','Dibayar','Sisa','Status'];
            const csvRows = allRows.map((row, i) => [
                i+1,
                new Date(row.tanggal).toLocaleDateString('id-ID'),
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
        function formatRp(n) { return 'Rp ' + Number(n).toLocaleString('id-ID'); }

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