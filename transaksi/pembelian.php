<?php
session_start();
require_once "../koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SESSION['role'] != "gudang" && $_SESSION['role'] != "owner") {
    header("Location: ../dashboard.php");
    exit;
}

$username = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user = mysqli_fetch_assoc($queryUser);

if (isset($_GET['get_batch'])) {

    header('Content-Type: application/json');

    $id_obat  = mysqli_real_escape_string($conn, $_GET['id_obat']);
    // Tanggal dikirim dari JS (value input tanggal), fallback ke hari ini
    $tanggal  = isset($_GET['tanggal']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tanggal'])
                ? $_GET['tanggal']
                : date('Y-m-d');

    $tgl_prefix = date('Ymd', strtotime($tanggal)); // misal: 20260502

    // Hitung berapa batch sudah ada untuk obat ini PADA tanggal yang sama
    $q = mysqli_query($conn, "
        SELECT COUNT(*) as total
        FROM pembelian
        WHERE id_obat = '$id_obat'
          AND DATE(tanggal) = '$tanggal'
    ");
    $data  = mysqli_fetch_assoc($q);
    $next  = (int)$data['total'] + 1;
    $urutan = str_pad($next, 3, '0', STR_PAD_LEFT); // 001, 002, ...

    $batch = $tgl_prefix . '-' . $urutan; // misal: 20260502-001

    echo json_encode([
        "batch"  => $batch,
        "prefix" => $tgl_prefix,
        "urutan" => $urutan
    ]);

    exit;
}

// ── AJAX: Simpan Pembelian ──
if (isset($_POST['ajax_simpan'])) {
    header('Content-Type: application/json');

    $tanggal      = $_POST['tanggal'];
    $id_supplier  = $_POST['id_supplier'];
    $id_obat      = $_POST['id_obat'];
    $jumlah       = (int)   $_POST['jumlah'];
    $harga_beli   = (float) $_POST['harga_beli'];
    $expired_date = $_POST['expired_date'];
    $dibayar      = (float) $_POST['dibayar'];

    if ($jumlah <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jumlah harus lebih dari 0']);
        exit;
    }

    if ($harga_beli < 0) {
        echo json_encode(['success' => false, 'message' => 'Harga tidak boleh minus']);
        exit;
    }

    // AUTO BATCH: format YYYYMMDD-NNN (tanggal pembelian + urutan ke-N di hari itu)
    $tgl_prefix  = date('Ymd', strtotime($tanggal)); // misal: 20260502
    $q_batch     = mysqli_query($conn, "
        SELECT COUNT(*) as total
        FROM pembelian
        WHERE id_obat = '$id_obat'
          AND DATE(tanggal) = '$tanggal'
    ");
    $d_batch     = mysqli_fetch_assoc($q_batch);
    $next_urutan = (int)$d_batch['total'] + 1;
    $batch       = $tgl_prefix . '-' . str_pad($next_urutan, 3, '0', STR_PAD_LEFT);

    $total = $jumlah * $harga_beli;

    // ── FIX: Dibayar tidak boleh melebihi total ──
    if ($dibayar > $total) $dibayar = $total;

    $sisa  = $total - $dibayar;

    if ($sisa <= 0) {
        $status = 'Lunas';
        $sisa = 0;
    } else {
        $status = 'Hutang';
    }

    $ok = mysqli_query(
        $conn,
        "INSERT INTO pembelian(tanggal,id_supplier,id_obat,jumlah,stok_sisa,harga_beli,batch,expired_date,total,dibayar,sisa,status_pembayaran) 
        VALUES('$tanggal','$id_supplier','$id_obat','$jumlah','$jumlah','$harga_beli','$batch','$expired_date','$total','$dibayar','$sisa','$status')"
    );

    if ($ok) {
        mysqli_query($conn, "UPDATE obat SET stok = stok + $jumlah WHERE id_obat='$id_obat'");
        echo json_encode(['success' => true, 'message' => 'Pembelian berhasil disimpan!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . mysqli_error($conn)]);
    }
    exit;
}

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

    // ── FIX: Validasi server-side, bayar tidak boleh melebihi sisa ──
    if ($bayar_tambah <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jumlah pembayaran harus lebih dari 0.']);
        exit;
    }
    if ($bayar_tambah > $sisa_lama) {
        echo json_encode([
            'success' => false,
            'message' => 'Jumlah pembayaran tidak boleh melebihi sisa hutang (Rp ' . number_format($sisa_lama, 0, ',', '.') . ').'
        ]);
        exit;
    }

    $sisa_baru = $sisa_lama - $bayar_tambah;
    if ($sisa_baru < 0) $sisa_baru = 0;

    $dibayar_baru_status = $sisa_baru <= 0 ? 'Lunas' : 'Hutang';

    mysqli_query(
        $conn,
        "UPDATE pembelian
         SET dibayar = dibayar + $bayar_tambah,
             sisa    = $sisa_baru,
             status_pembayaran = '$dibayar_baru_status'
         WHERE id_pembelian='$id_pembelian'"
    );

    echo json_encode(['success' => true, 'sisa_baru' => (float)$sisa_baru, 'status' => $dibayar_baru_status]);
    exit;
}

// ── Fetch dropdown data ──
$supplierResult = mysqli_query($conn, "SELECT * FROM supplier ORDER BY nama_supplier");
$obatResult     = mysqli_query($conn, "SELECT * FROM obat ORDER BY nama_obat");

// ── Fetch history ──
$historyResult  = mysqli_query(
    $conn,
    "SELECT p.*, s.nama_supplier, o.nama_obat
     FROM pembelian p
     LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
     LEFT JOIN obat o     ON p.id_obat = o.id_obat
     ORDER BY p.tanggal DESC"
);

$stokTipis = mysqli_query($conn, "SELECT * FROM obat WHERE stok < stok_minimum ORDER BY stok ASC LIMIT 10");
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Pembelian — Apotek</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/pembelian.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <!-- Navigation -->
    <nav class="topnav">
        <a href="../dashboard.php" class="sb-brand">
            <img src="../uploads/logo.png" alt="Logo Apotek" style="height: 50px;" class="logo">
        </a>
        <div class="breadcrumb">
            <i class="fas fa-chevron-right"></i>
            <span class="current">Pembelian</span>
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
            <?php if ($user['role'] != 'admin'): ?>
<<<<<<< HEAD
            <div class="sb-sec">Core</div>
            <a class="sb-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
=======
                <div class="sb-sec">Core</div>
                <a class="sb-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
>>>>>>> 8d09b546e690e945f8c9d996dca731ad8a4e7666
            <?php endif; ?>
            <div class="sb-sec">Master Data</div>
            <a class="sb-link" href="../master/kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <?php if ($user['role'] != 'kasir'): ?>
                <a class="sb-link" href="../master/supplier.php"><i class="fas fa-truck"></i> Supplier</a>
            <?php endif; ?>
            <a class="sb-link" href="../master/obat.php"><i class="fas fa-pills"></i> Obat</a>
            <a class="sb-link" href="../master/member.php"><i class="fas fa-user-friends"></i> Member</a>
            <?php if ($user['role'] == 'owner'): ?>
                <div class="sb-sec">Transaksi</div>
                <a class="sb-link active" href="pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
                <a class="sb-link" href="penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
                <div class="sb-sec">Laporan</div>
                <a class="sb-link" href="../laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
                <a class="sb-link" href="../laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
                <a class="sb-link" href="../laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
            <?php elseif ($user['role'] == 'kasir'): ?>
                <div class="sb-sec">Transaksi</div>
                <a class="sb-link" href="penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
            <?php endif; ?>
            <div class="sb-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="main-content">

            <div class="tabs-bar">
                <button class="tab-btn active" onclick="switchTab('beli',this)">
                    <i class="fas fa-cash-register"></i> Transaksi Pembelian
                </button>
                <button class="tab-btn" onclick="switchTab('history',this)">
                    <i class="fas fa-history"></i> Riwayat Pembelian
                </button>
            </div>

            <!-- TAB: FORM PEMBELIAN -->
            <div id="tab-beli" style="padding:20px;display:flex;flex-direction:column;gap:20px;">

                <div class="form-grid">

                    <!-- Form Pembelian -->
                    <div class="form-card">
                        <h3><i class="fas fa-arrow-down text-green"></i> Tambah Pembelian</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Tanggal Pembelian</label>
                                <input type="date" id="f-tanggal" class="form-input" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group">
                                <label>Supplier</label>
                                <select id="f-supplier" class="form-select">
                                    <option value="">-- Pilih Supplier --</option>
                                    <?php while ($s = mysqli_fetch_assoc($supplierResult)): ?>
                                        <option value="<?= $s['id_supplier'] ?>"><?= htmlspecialchars($s['nama_supplier']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Obat</label>
                            <select id="f-obat" class="form-select" onchange="generateBatch()">
                                <option value="">-- Pilih Obat --</option>
                                <?php while ($o = mysqli_fetch_assoc($obatResult)): ?>
                                    <option value="<?= $o['id_obat'] ?>"><?= htmlspecialchars($o['nama_obat']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Batch / No. Lot</label>
                                <input type="text" id="f-batch" class="form-input" readonly>
                            </div>
                            <div class="form-group">
                                <label>Expired Date</label>
                                <input type="date" id="f-expired" class="form-input">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Jumlah (pcs)</label>
                                <input type="number" id="f-jumlah" class="form-input" placeholder="0" min="1" oninput="calcTotal()">
                            </div>
                            <div class="form-group">
                                <label>Harga Beli / pcs</label>
                                <input type="number" id="f-harga" class="form-input" placeholder="0" min="0" oninput="calcTotal()">
                            </div>
                        </div>

                        <div class="total-preview">
                            <span>Total Pembelian</span>
                            <span id="preview-total">Rp 0</span>
                        </div>

                        <div class="form-group">
                            <label>Dibayar Sekarang</label>
                            <!-- FIX: max diset dinamis via JS agar tidak bisa melebihi total -->
                            <input type="number" id="f-dibayar" class="form-input" placeholder="0" min="0" oninput="calcSisa()">
                            <small id="dibayar-hint" style="color:var(--muted);font-size:11.5px;margin-top:4px;display:block">
                                Maksimal pembayaran sesuai total pembelian
                            </small>
                        </div>

                        <div class="sisa-preview" id="sisa-preview" style="display:none">
                            <span>Sisa Hutang</span>
                            <span id="preview-sisa">Rp 0</span>
                        </div>

                        <button class="btn-full btn-primary" onclick="submitPembelian()">
                            <i class="fas fa-save"></i> Simpan Pembelian
                        </button>
                    </div>

                    <!-- Right column: Hutang + Stok Menipis -->
                    <div style="display:flex;flex-direction:column;gap:20px;">

                        <!-- Hutang summary -->
                        <div class="form-card" style="background:linear-gradient(135deg,#fff8f0,#fff)">
                            <h3><i class="fas fa-file-invoice-dollar text-amber"></i> Ringkasan Hutang</h3>
                            <?php
                            $hutangResult = mysqli_query(
                                $conn,
                                "SELECT p.id_pembelian, o.nama_obat, s.nama_supplier, p.sisa, p.tanggal
                                 FROM pembelian p
                                 LEFT JOIN obat o ON p.id_obat = o.id_obat
                                 LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
                                 WHERE p.status_pembayaran = 'Hutang'
                                 ORDER BY p.tanggal DESC LIMIT 5"
                            );
                            $rows = [];
                            while ($r = mysqli_fetch_assoc($hutangResult)) $rows[] = $r;
                            if (!$rows):
                            ?>
                                <div style="text-align:center;padding:20px 0;color:var(--muted);font-size:13px">
                                    <i class="fas fa-check-circle" style="font-size:28px;color:var(--green-light);display:block;margin-bottom:8px"></i>
                                    Tidak ada hutang saat ini 🎉
                                </div>
                            <?php else: ?>
                                <div class="alert-list">
                                    <?php foreach ($rows as $r): ?>
                                        <div class="alert-item">
                                            <div>
                                                <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($r['nama_obat']) ?></div>
                                                <div style="font-size:11.5px;color:var(--muted)"><?= htmlspecialchars($r['nama_supplier']) ?> · <?= $r['tanggal'] ?></div>
                                            </div>
                                            <div style="display:flex;align-items:center;gap:10px">
                                                <span class="sisa-amount" style="font-size:13px">Rp <?= number_format($r['sisa'], 0, ',', '.') ?></span>
                                                <button class="btn-bayar" onclick="openBayarModal(<?= $r['id_pembelian'] ?>, '<?= addslashes($r['nama_obat']) ?>', <?= number_format((float)$r['sisa'], 2, '.', '') ?>)">
                                                    Bayar
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Stok Menipis -->
                        <div class="alert-box warning">
                            <h3><i class="fas fa-exclamation-triangle"></i> Peringatan Stok Menipis</h3>
                            <?php
                            mysqli_data_seek($stokTipis, 0);
                            $stokRows = [];
                            while ($r = mysqli_fetch_assoc($stokTipis)) $stokRows[] = $r;
                            if (!$stokRows):
                            ?>
                                <div style="font-size:13px;color:var(--muted);padding:8px 0">
                                    Semua stok masih aman ✅
                                </div>
                            <?php else: ?>
                                <div class="alert-list">
                                    <?php foreach ($stokRows as $r): ?>
                                        <div class="alert-item">
                                            <span><?= htmlspecialchars($r['nama_obat']) ?></span>
                                            <span class="alert-badge <?= $r['stok'] <= 5 ? 'critical' : '' ?>">
                                                Sisa <?= $r['stok'] ?> pcs
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div><!-- end right column -->

                </div><!-- end form-grid -->

            </div><!-- end tab-beli -->

            <!-- TAB: HISTORY -->
            <div id="tab-history" style="padding:20px;display:none;">
                <div class="history-card">
                    <div class="history-card-header">
                        <i class="fas fa-table"></i> Riwayat Pembelian
                    </div>
                    <div style="overflow-x:auto">
                        <table class="data-table" id="history-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Supplier</th>
                                    <th>Obat</th>
                                    <th>Batch</th>
                                    <th>Expired</th>
                                    <th>Jumlah</th>
                                    <th>Total</th>
                                    <th>Dibayar</th>
                                    <th>Sisa</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="history-tbody">
                                <?php
                                $no = 1;
                                while ($row = mysqli_fetch_assoc($historyResult)):
                                    $isHutang = $row['status_pembayaran'] === 'Hutang';
                                ?>
                                    <tr id="row-<?= $row['id_pembelian'] ?>">
                                        <td><?= $no++ ?></td>
                                        <td><?= $row['tanggal'] ?></td>
                                        <td><?= htmlspecialchars($row['nama_supplier'] ?? '-') ?></td>
                                        <td style="font-weight:600"><?= htmlspecialchars($row['nama_obat'] ?? '-') ?></td>
                                        <td><span style="background:var(--bg);padding:2px 8px;border-radius:6px;font-size:12px"><?= htmlspecialchars($row['batch'] ?? '-') ?></span></td>
                                        <td><?= $row['expired_date'] ?? '-' ?></td>
                                        <td><?= $row['jumlah'] ?> pcs</td>
                                        <td style="font-weight:700">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>
                                        <td style="color:var(--green);font-weight:600">Rp <?= number_format($row['dibayar'], 0, ',', '.') ?></td>
                                        <td class="sisa-cell-<?= $row['id_pembelian'] ?>" style="font-weight:700;<?= $isHutang ? 'color:var(--red)' : '' ?>">
                                            Rp <?= number_format($row['sisa'], 0, ',', '.') ?>
                                        </td>
                                        <td>
                                            <span class="badge-status badge-<?= strtolower($row['status_pembayaran']) ?> status-cell-<?= $row['id_pembelian'] ?>">
                                                <?= $row['status_pembayaran'] === 'Lunas' ? '✓ Lunas' : '⚠ Hutang' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div><!-- end tab-history -->

        </div><!-- end main-content -->
    </div><!-- end app-body -->

    <!-- MODAL: Bayar Hutang -->
    <div class="modal-overlay" id="modal-bayar">
        <div class="modal-box">
            <div class="modal-icon amber"><i class="fas fa-money-bill-wave"></i></div>
            <div class="modal-title">Bayar Hutang</div>
            <div class="modal-subtitle" id="modal-bayar-subtitle">Masukkan jumlah pembayaran</div>

            <div class="modal-info-row">
                <span>Nama Obat</span>
                <span id="modal-nama-obat">—</span>
            </div>
            <div class="modal-info-row">
                <span>Sisa Hutang</span>
                <span id="modal-sisa-text" style="color:var(--red)">—</span>
            </div>

            <div class="modal-field" style="margin-top:14px">
                <label>Jumlah Dibayar (Rp)</label>
                <!-- FIX: max & attr diset saat modal dibuka, input realtime divalidasi -->
                <input type="number" id="input-bayar" placeholder="Masukkan nominal..." min="1" oninput="previewBayar()">
                <!-- Hint sisa hutang yang bisa dibayar -->
                <small id="bayar-hint" style="color:var(--muted);font-size:11.5px;margin-top:5px;display:block">
                    Maksimal: <strong id="bayar-hint-max">—</strong>
                </small>
                <!-- Pesan error jika melebihi sisa -->
                <div id="bayar-error"
                     style="display:none;margin-top:6px;padding:7px 10px;background:#fef2f2;border:1px solid #fca5a5;
                            border-radius:7px;color:#dc2626;font-size:12px;font-weight:600">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="bayar-error-msg">Pembayaran melebihi sisa hutang!</span>
                </div>
            </div>

            <div class="sisa-preview" id="modal-sisa-preview" style="display:none;margin-top:4px">
                <span>Sisa setelah bayar</span>
                <span id="modal-sisa-after">Rp 0</span>
            </div>

            <div class="modal-footer">
                <button class="modal-btn secondary" onclick="closeModal('modal-bayar')">Batal</button>
                <button class="modal-btn primary" id="btn-konfirmasi-bayar" onclick="submitBayar()">
                    <i class="fas fa-check"></i> Konfirmasi Bayar
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL: Sukses -->
    <div class="modal-overlay" id="modal-sukses">
        <div class="modal-box" style="text-align:center">
            <div class="modal-icon green"><i class="fas fa-check"></i></div>
            <div class="modal-title">Berhasil!</div>
            <div class="modal-subtitle" id="modal-sukses-text">Operasi berhasil dilakukan.</div>
            <div class="modal-footer">
                <button class="modal-btn primary" onclick="closeModal('modal-sukses')">Oke</button>
            </div>
        </div>
    </div>

    <!-- MODAL: Error -->
    <div class="modal-overlay" id="modal-error">
        <div class="modal-box" style="text-align:center">
            <div class="modal-icon red"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="modal-title">Terjadi Kesalahan</div>
            <div class="modal-subtitle" id="modal-error-text">Silakan coba lagi.</div>
            <div class="modal-footer">
                <button class="modal-btn primary" onclick="closeModal('modal-error')">Oke</button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div class="toast" id="toast"></div>

    <script>
        function switchTab(tab, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-beli').style.display    = tab === 'beli'    ? 'flex'  : 'none';
            document.getElementById('tab-history').style.display = tab === 'history' ? 'block' : 'none';
        }

        // ── Calc helpers ──
        function formatRp(n) {
            return 'Rp ' + Number(n).toLocaleString('id-ID');
        }

        function calcTotal() {
            const j = parseFloat(document.getElementById('f-jumlah').value) || 0;
            const h = parseFloat(document.getElementById('f-harga').value)  || 0;
            document.getElementById('preview-total').textContent = formatRp(j * h);

            // FIX: Update max attribute pada field dibayar setiap total berubah
            const total = j * h;
            const dibayarEl = document.getElementById('f-dibayar');
            dibayarEl.max = total;
            calcSisa();
        }

        function calcSisa() {
            const j = parseFloat(document.getElementById('f-jumlah').value)  || 0;
            const h = parseFloat(document.getElementById('f-harga').value)   || 0;
            const d = parseFloat(document.getElementById('f-dibayar').value) || 0;
            const total = j * h;

            // FIX: Clamp nilai dibayar agar tidak melebihi total
            if (d > total && total > 0) {
                document.getElementById('f-dibayar').value = total;
            }

            const dibayarFinal = Math.min(d, total);
            const sisa         = total - dibayarFinal;
            const previewEl    = document.getElementById('sisa-preview');

            if (dibayarFinal > 0 && sisa > 0) {
                previewEl.style.display = 'flex';
                document.getElementById('preview-sisa').textContent = formatRp(sisa);
            } else {
                previewEl.style.display = 'none';
            }
        }

        // ── Submit Pembelian ──
        function submitPembelian() {
            const tanggal     = document.getElementById('f-tanggal').value;
            const id_supplier = document.getElementById('f-supplier').value;
            const id_obat     = document.getElementById('f-obat').value;
            const expired     = document.getElementById('f-expired').value;
            const batch       = document.getElementById('f-batch').value;
            const jumlah      = parseFloat(document.getElementById('f-jumlah').value) || 0;
            const harga       = parseFloat(document.getElementById('f-harga').value)  || 0;
            const total       = jumlah * harga;
            let   dibayar     = parseFloat(document.getElementById('f-dibayar').value) || 0;

            if (!tanggal || !id_supplier || !id_obat || !expired || !jumlah || !harga) {
                showToast('Harap isi semua field yang diperlukan!', true);
                return;
            }
            if (jumlah <= 0) { showToast('Jumlah harus lebih dari 0!', true); return; }
            if (harga  < 0)  { showToast('Harga tidak boleh minus!', true);   return; }

            // FIX: Clamp dibayar di sisi klien juga
            if (dibayar > total) {
                dibayar = total;
                document.getElementById('f-dibayar').value = total;
            }

            const fd = new FormData();
            fd.append('ajax_simpan',  '1');
            fd.append('tanggal',      tanggal);
            fd.append('id_supplier',  id_supplier);
            fd.append('id_obat',      id_obat);
            fd.append('expired_date', expired);
            fd.append('batch',        batch);
            fd.append('jumlah',       jumlah);
            fd.append('harga_beli',   harga);
            fd.append('dibayar',      dibayar);

            fetch(window.location.href, { method:'POST', body:fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modal-sukses-text').textContent = data.message;
                        openModal('modal-sukses');
                        setTimeout(() => { closeModal('modal-sukses'); location.reload(); }, 1800);
                    } else {
                        document.getElementById('modal-error-text').textContent = data.message;
                        openModal('modal-error');
                    }
                })
                .catch(() => {
                    document.getElementById('modal-error-text').textContent = 'Koneksi gagal. Coba lagi.';
                    openModal('modal-error');
                });
        }

        // ── Bayar Hutang ──
        let activeBayarId   = null;
        let activeBayarSisa = 0;

        function openBayarModal(id, nama, sisa) {
            activeBayarId   = id;
            activeBayarSisa = sisa;

            document.getElementById('modal-nama-obat').textContent  = nama;
            document.getElementById('modal-sisa-text').textContent  = formatRp(sisa);
            document.getElementById('input-bayar').value            = '';
            document.getElementById('input-bayar').max              = sisa;   // FIX: set max
            document.getElementById('bayar-hint-max').textContent   = formatRp(sisa);
            document.getElementById('bayar-error').style.display    = 'none';
            document.getElementById('modal-sisa-preview').style.display = 'none';
            document.getElementById('btn-konfirmasi-bayar').disabled    = false;

            openModal('modal-bayar');
        }

        function previewBayar() {
            const bayar      = parseFloat(document.getElementById('input-bayar').value) || 0;
            const errorEl    = document.getElementById('bayar-error');
            const errorMsg   = document.getElementById('bayar-error-msg');
            const btnKonfirm = document.getElementById('btn-konfirmasi-bayar');
            const previewEl  = document.getElementById('modal-sisa-preview');

            // FIX: Validasi realtime – jika melebihi sisa, tampilkan error & block tombol
            if (bayar > activeBayarSisa) {
                errorMsg.textContent            = `Pembayaran melebihi sisa hutang! Maksimal ${formatRp(activeBayarSisa)}.`;
                errorEl.style.display           = 'block';
                btnKonfirm.disabled             = true;
                btnKonfirm.style.opacity        = '0.5';
                btnKonfirm.style.cursor         = 'not-allowed';
                previewEl.style.display         = 'none';
                return;
            } else {
                errorEl.style.display    = 'none';
                btnKonfirm.disabled      = false;
                btnKonfirm.style.opacity = '1';
                btnKonfirm.style.cursor  = 'pointer';
            }

            if (bayar > 0) {
                const sisa_after = activeBayarSisa - bayar;
                previewEl.style.display = 'flex';
                const afterEl           = document.getElementById('modal-sisa-after');
                afterEl.textContent     = sisa_after <= 0 ? '✓ Lunas' : formatRp(sisa_after);
                afterEl.style.color     = sisa_after <= 0 ? 'var(--green)' : 'var(--amber)';
            } else {
                previewEl.style.display = 'none';
            }
        }

        function submitBayar() {
            const bayar = parseFloat(document.getElementById('input-bayar').value) || 0;

            if (!bayar || bayar <= 0) {
                showToast('Masukkan jumlah pembayaran!', true);
                return;
            }

            // FIX: Guard terakhir sebelum kirim — pastikan tidak melebihi sisa
            if (bayar > activeBayarSisa) {
                showToast(`Pembayaran tidak boleh melebihi sisa hutang (${formatRp(activeBayarSisa)})!`, true);
                return;
            }

            const fd = new FormData();
            fd.append('ajax_bayar',    '1');
            fd.append('id_pembelian',  activeBayarId);
            fd.append('bayar_tambah',  bayar);

            fetch(window.location.href, { method:'POST', body:fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        closeModal('modal-bayar');
                        const sisaCell   = document.querySelector(`.sisa-cell-${activeBayarId}`);
                        const statusCell = document.querySelector(`.status-cell-${activeBayarId}`);
                        if (sisaCell)   sisaCell.textContent = formatRp(data.sisa_baru);
                        if (statusCell) {
                            statusCell.textContent = data.status === 'Lunas' ? '✓ Lunas' : '⚠ Hutang';
                            statusCell.className   = `badge-status badge-${data.status.toLowerCase()} status-cell-${activeBayarId}`;
                        }
                        if (data.status === 'Lunas') {
                            const btn = document.querySelector(`#row-${activeBayarId} .btn-bayar`);
                            if (btn) btn.parentElement.innerHTML = '<span style="color:var(--muted);font-size:12px">—</span>';
                        }
                        showToast('Pembayaran berhasil dicatat!');
                    } else {
                        // FIX: Tampilkan pesan error dari server (termasuk validasi sisa)
                        document.getElementById('modal-error-text').textContent = data.message;
                        openModal('modal-error');
                    }
                })
                .catch(() => {
                    document.getElementById('modal-error-text').textContent = 'Koneksi gagal.';
                    openModal('modal-error');
                });
        }

        // ── Modal helpers ──
        function openModal(id)  { document.getElementById(id).classList.add('show');    }
        function closeModal(id) { document.getElementById(id).classList.remove('show'); }

        // ── Toast ──
        function showToast(msg, error = false) {
            const t = document.getElementById('toast');
            t.innerHTML  = `<i class="fas fa-${error ? 'exclamation-circle' : 'check-circle'}"></i> ${msg}`;
            t.className  = 'toast show' + (error ? ' error' : '');
            setTimeout(() => t.className = 'toast', 2800);
        }

        // ── Dropdown user ──
        function toggleDropdown() {
            var menu = document.getElementById('ddmenu');
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }
        document.addEventListener('click', function(e) {
            var wrap = document.getElementById('ddwrap');
            if (wrap && !wrap.contains(e.target)) document.getElementById('ddmenu').style.display = 'none';
        });

        function generateBatch() {
            const obat    = document.getElementById('f-obat').value;
            const tanggal = document.getElementById('f-tanggal').value;

            if (!obat) {
                document.getElementById('f-batch').value = '';
                return;
            }

            const url = `pembelian.php?get_batch=1&id_obat=${encodeURIComponent(obat)}&tanggal=${encodeURIComponent(tanggal)}`;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    document.getElementById('f-batch').value = data.batch;
                });
        }

        // Auto-refresh batch juga saat tanggal diubah (selama obat sudah dipilih)
        document.getElementById('f-tanggal').addEventListener('change', function () {
            if (document.getElementById('f-obat').value) generateBatch();
        });
    </script>

</body>
</html>