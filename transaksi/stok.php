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

$username  = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user      = mysqli_fetch_assoc($queryUser);

// ════════════════════════════════════════
//  AJAX: Konfirmasi Penerimaan Stok Masuk
// ════════════════════════════════════════
if (isset($_POST['ajax_terima'])) {
    header('Content-Type: application/json');

    $id_pembelian = (int) $_POST['id_pembelian'];

    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id_obat, jumlah, status_penerimaan FROM pembelian WHERE id_pembelian='$id_pembelian'"
    ));

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Data pembelian tidak ditemukan.']);
        exit;
    }

    if ($row['status_penerimaan'] === 'Diterima') {
        echo json_encode(['success' => false, 'message' => 'Barang ini sudah pernah diterima.']);
        exit;
    }

    $id_obat = $row['id_obat'];
    $jumlah  = $row['jumlah'];

    mysqli_query($conn, "UPDATE pembelian SET status_penerimaan='Diterima' WHERE id_pembelian='$id_pembelian'");
    mysqli_query($conn, "UPDATE obat SET stok = stok + $jumlah WHERE id_obat='$id_obat'");

    echo json_encode(['success' => true, 'message' => 'Barang berhasil diterima. Stok telah diperbarui.']);
    exit;
}

// ════════════════════════════════════════
//  AJAX: Catat Stok Keluar Manual
// ════════════════════════════════════════
if (isset($_POST['ajax_keluar'])) {
    header('Content-Type: application/json');

    $id_obat    = (int)   $_POST['id_obat'];
    $jumlah     = (int)   $_POST['jumlah'];
    $keterangan = mysqli_real_escape_string($conn, $_POST['keterangan']);
    $tanggal    = $_POST['tanggal'];

    $cek = mysqli_fetch_assoc(mysqli_query($conn, "SELECT stok, nama_obat FROM obat WHERE id_obat='$id_obat'"));
    if (!$cek) {
        echo json_encode(['success' => false, 'message' => 'Obat tidak ditemukan.']);
        exit;
    }
    if ($cek['stok'] < $jumlah) {
        echo json_encode(['success' => false, 'message' => "Stok tidak mencukupi. Stok {$cek['nama_obat']} saat ini: {$cek['stok']} pcs."]);
        exit;
    }

    mysqli_query($conn, "INSERT INTO stok_keluar (id_obat, tanggal, jumlah, keterangan) VALUES ('$id_obat','$tanggal','$jumlah','$keterangan')");
    mysqli_query($conn, "UPDATE obat SET stok = stok - $jumlah WHERE id_obat='$id_obat'");

    echo json_encode(['success' => true, 'message' => 'Stok keluar berhasil dicatat!']);
    exit;
}

// ════════════════════════════════════════
//  AJAX: Buang Obat Expired
// ════════════════════════════════════════
if (isset($_POST['ajax_buang'])) {
    header('Content-Type: application/json');

    $id_obat      = (int) $_POST['id_obat'];
    $jumlah       = (int) $_POST['jumlah'];
    $id_pembelian = (int) $_POST['id_pembelian'];
    $tanggal      = date("Y-m-d");

    mysqli_query($conn, "INSERT INTO stok_keluar (id_obat, tanggal, jumlah, keterangan) VALUES ('$id_obat','$tanggal','$jumlah','Expired')");
    mysqli_query($conn, "UPDATE obat SET stok = stok - $jumlah WHERE id_obat='$id_obat'");
    // tandai pembelian agar tidak muncul lagi di daftar expired
    mysqli_query($conn, "UPDATE pembelian SET status_penerimaan='Dibuang' WHERE id_pembelian='$id_pembelian'");

    echo json_encode(['success' => true, 'message' => 'Obat expired berhasil dibuang dan stok dikurangi.']);
    exit;
}

// ════════════════════════════════════════
//  Fetch Data Halaman
// ════════════════════════════════════════

// Stok masuk: semua pembelian status Menunggu
$masukMenunggu = mysqli_query($conn,
    "SELECT p.*, s.nama_supplier, o.nama_obat
     FROM pembelian p
     LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
     LEFT JOIN obat o     ON p.id_obat = o.id_obat
     WHERE p.status_penerimaan = 'Menunggu'
     ORDER BY p.tanggal DESC"
);

// Stok masuk: riwayat yang sudah diterima
$masukDiterima = mysqli_query($conn,
    "SELECT p.*, s.nama_supplier, o.nama_obat
     FROM pembelian p
     LEFT JOIN supplier s ON p.id_supplier = s.id_supplier
     LEFT JOIN obat o     ON p.id_obat = o.id_obat
     WHERE p.status_penerimaan = 'Diterima'
     ORDER BY p.tanggal DESC"
);

// Stok keluar: obat expired (belum dibuang)
$expiredResult = mysqli_query($conn,
    "SELECT p.*, o.nama_obat, o.stok AS stok_sekarang
     FROM pembelian p
     JOIN obat o ON p.id_obat = o.id_obat
     WHERE p.expired_date <= CURDATE()
       AND p.status_penerimaan NOT IN ('Dibuang')
     ORDER BY p.expired_date ASC"
);

// Stok keluar: riwayat
$riwayatKeluar = mysqli_query($conn,
    "SELECT sk.*, o.nama_obat
     FROM stok_keluar sk
     JOIN obat o ON sk.id_obat = o.id_obat
     ORDER BY sk.id_stok_keluar DESC"
);

// Obat dropdown
$obatResult = mysqli_query($conn, "SELECT * FROM obat ORDER BY nama_obat");

$jumlahMenunggu = mysqli_num_rows($masukMenunggu);
$jumlahExpired  = mysqli_num_rows($expiredResult);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Stok — Apotek</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/pembelian.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── Badge tambahan ── */
        .badge-menunggu  { background:#fef9ec;color:#b45309;border:1px solid #fcd34d;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600; }
        .badge-diterima  { background:#f0fdf4;color:#15803d;border:1px solid #86efac;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600; }
        .badge-expired-k { background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600; }
        .badge-keluar    { background:#f8fafc;color:#64748b;border:1px solid #cbd5e1;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600; }
        .badge-retur     { background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600; }

        /* Tab badge counter */
        .tab-counter {
            background:#dc2626;color:#fff;
            border-radius:20px;padding:1px 8px;
            font-size:11px;margin-left:5px;
            display:inline-block;
        }
        .tab-counter.amber { background:#d97706; }

        /* Tombol konfirmasi */
        .btn-terima {
            background: var(--primary);
            color: #fff; border: none; border-radius: 8px;
            padding: 6px 14px; font-size: 12.5px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            transition: background .2s;
        }
        .btn-terima:hover { background: #1d4ed8; }

        /* Tombol buang */
        .btn-buang {
            background:#dc2626;color:#fff;border:none;border-radius:8px;
            padding:6px 14px;font-size:12.5px;font-weight:600;cursor:pointer;
            display:inline-flex;align-items:center;gap:6px;transition:background .2s;
        }
        .btn-buang:hover { background:#b91c1c; }

        /* Stok info hint */
        .stok-hint {
            background:var(--bg);border-radius:10px;padding:9px 14px;
            font-size:13px;color:var(--muted);margin-top:-4px;display:none;
        }
        .stok-hint.show { display:flex; align-items:center; gap:8px; }

        /* Sub-tab inside a main tab */
        .subtab-bar {
            display:flex;gap:8px;margin-bottom:16px;
            border-bottom:2px solid var(--border);padding-bottom:0;
        }
        .subtab-btn {
            background:none;border:none;padding:9px 16px;
            font-size:13.5px;font-weight:600;color:var(--muted);
            cursor:pointer;border-bottom:3px solid transparent;
            margin-bottom:-2px;transition:color .2s,border-color .2s;
            font-family:inherit;
        }
        .subtab-btn.active { color:var(--primary);border-bottom-color:var(--primary); }
        .subtab-btn:hover  { color:var(--text); }

        /* Banner peringatan expired */
        .expired-alert-banner {
            background:linear-gradient(135deg,#fff1f1,#fff);
            border:1.5px solid #fca5a5;border-radius:12px;
            padding:13px 18px;display:flex;align-items:center;gap:12px;
            font-size:13px;color:#7f1d1d;margin-bottom:8px;
        }
        .expired-alert-banner i { font-size:20px;color:#dc2626;flex-shrink:0; }

        /* Keterangan select */
        .keterangan-select {
            width:100%;padding:10px 14px;border:1.5px solid var(--border);
            border-radius:10px;font-family:inherit;font-size:14px;
            background:var(--bg);color:var(--text);outline:none;transition:border-color .2s;
        }
        .keterangan-select:focus { border-color:var(--primary); }

        /* Info banner */
        .info-banner {
            background:linear-gradient(135deg,#fffbeb,#fff);border:1.5px solid #fcd34d;
            border-radius:12px;padding:12px 18px;display:flex;align-items:center;gap:12px;
            font-size:13px;color:#78350f;
        }
        .info-banner i { font-size:18px;color:#d97706;flex-shrink:0; }
    </style>
</head>
<body>

    <!-- Navigation -->
    <nav class="topnav">
        <a href="../dashboard.php" class="sb-brand">
            <img src="../uploads/logo.png" alt="Logo Apotek" style="height:125px;" class="logo">
        </a>
        <div class="breadcrumb">
            <i class="fas fa-chevron-right"></i>
            <span class="current">Stok</span>
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
            <a class="sb-link" href="pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
            <a class="sb-link active" href="stok.php"><i class="fas fa-boxes"></i> Stok</a>
            <a class="sb-link" href="penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
            <div class="sb-sec">Laporan</div>
            <a class="sb-link" href="../laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
            <a class="sb-link" href="../laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
            <a class="sb-link" href="../laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
            <div class="sb-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <!-- ═══════════ MAIN ═══════════ -->
        <div class="main-content">

            <div class="tabs-bar">
                <button class="tab-btn active" onclick="switchTab('masuk',this)">
                    <i class="fas fa-arrow-down"></i> Stok Masuk
                    <?php if ($jumlahMenunggu > 0): ?>
                        <span class="tab-counter amber"><?= $jumlahMenunggu ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" onclick="switchTab('keluar',this)">
                    <i class="fas fa-arrow-up"></i> Stok Keluar
                    <?php if ($jumlahExpired > 0): ?>
                        <span class="tab-counter"><?= $jumlahExpired ?> expired</span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- ══════════════════════════════════════════
                 TAB 1: STOK MASUK
            ═══════════════════════════════════════════ -->
            <div id="tab-masuk" style="padding:20px;display:flex;flex-direction:column;gap:16px;">

                <!-- Sub-tab -->
                <div class="subtab-bar">
                    <button class="subtab-btn active" onclick="switchSubTab('masuk','menunggu',this)">
                        <i class="fas fa-clock"></i> Menunggu Konfirmasi
                        <?php if ($jumlahMenunggu > 0): ?>
                            <span class="tab-counter amber" style="font-size:10.5px"><?= $jumlahMenunggu ?></span>
                        <?php endif; ?>
                    </button>
                    <button class="subtab-btn" onclick="switchSubTab('masuk','diterima',this)">
                        <i class="fas fa-check-circle"></i> Sudah Diterima
                    </button>
                </div>

                <!-- Sub: Menunggu -->
                <div id="subtab-masuk-menunggu">
                    <?php if ($jumlahMenunggu === 0): ?>
                        <div style="text-align:center;padding:32px;color:var(--muted)">
                            <i class="fas fa-check-circle" style="font-size:32px;color:#22c55e;display:block;margin-bottom:10px"></i>
                            Semua pembelian sudah dikonfirmasi penerimaannya ✅
                        </div>
                    <?php else: ?>
                        <div class="info-banner" style="margin-bottom:4px">
                            <i class="fas fa-info-circle"></i>
                            <span>Klik <strong>Konfirmasi Terima</strong> setelah barang fisik diterima di apotek. Stok akan bertambah otomatis.</span>
                        </div>
                        <div class="history-card">
                            <div class="history-card-header">
                                <i class="fas fa-clock"></i> Pembelian Menunggu Penerimaan
                            </div>
                            <div style="overflow-x:auto">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>No</th><th>Tanggal</th><th>Supplier</th><th>Obat</th>
                                            <th>Batch</th><th>Expired</th><th>Jumlah</th><th>Total</th>
                                            <th>Pembayaran</th><th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $no = 1;
                                        mysqli_data_seek($masukMenunggu, 0);
                                        while ($row = mysqli_fetch_assoc($masukMenunggu)):
                                        ?>
                                            <tr id="mrow-<?= $row['id_pembelian'] ?>">
                                                <td><?= $no++ ?></td>
                                                <td><?= $row['tanggal'] ?></td>
                                                <td><?= htmlspecialchars($row['nama_supplier'] ?? '-') ?></td>
                                                <td style="font-weight:600"><?= htmlspecialchars($row['nama_obat'] ?? '-') ?></td>
                                                <td><span style="background:var(--bg);padding:2px 8px;border-radius:6px;font-size:12px"><?= htmlspecialchars($row['batch'] ?? '-') ?></span></td>
                                                <td><?= $row['expired_date'] ?? '-' ?></td>
                                                <td><?= $row['jumlah'] ?> pcs</td>
                                                <td style="font-weight:700">Rp <?= number_format($row['total'],0,',','.') ?></td>
                                                <td>
                                                    <span class="badge-status badge-<?= strtolower($row['status_pembayaran']) ?>">
                                                        <?= $row['status_pembayaran'] === 'Lunas' ? '✓ Lunas' : '⚠ Hutang' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="btn-terima" onclick="openTerimaModal(<?= $row['id_pembelian'] ?>, '<?= addslashes($row['nama_obat']) ?>', <?= $row['jumlah'] ?>, '<?= $row['nama_supplier'] ?>')">
                                                        <i class="fas fa-check"></i> Konfirmasi Terima
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sub: Diterima -->
                <div id="subtab-masuk-diterima" style="display:none">
                    <div class="history-card">
                        <div class="history-card-header">
                            <i class="fas fa-check-circle"></i> Riwayat Penerimaan Barang
                        </div>
                        <div style="overflow-x:auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>No</th><th>Tanggal</th><th>Supplier</th><th>Obat</th>
                                        <th>Batch</th><th>Expired</th><th>Jumlah</th><th>Total</th>
                                        <th>Pembayaran</th><th>Penerimaan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $no = 1;
                                    $hasDiterima = false;
                                    while ($row = mysqli_fetch_assoc($masukDiterima)):
                                        $hasDiterima = true;
                                    ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td><?= $row['tanggal'] ?></td>
                                            <td><?= htmlspecialchars($row['nama_supplier'] ?? '-') ?></td>
                                            <td style="font-weight:600"><?= htmlspecialchars($row['nama_obat'] ?? '-') ?></td>
                                            <td><span style="background:var(--bg);padding:2px 8px;border-radius:6px;font-size:12px"><?= htmlspecialchars($row['batch'] ?? '-') ?></span></td>
                                            <td><?= $row['expired_date'] ?? '-' ?></td>
                                            <td><?= $row['jumlah'] ?> pcs</td>
                                            <td style="font-weight:700">Rp <?= number_format($row['total'],0,',','.') ?></td>
                                            <td>
                                                <span class="badge-status badge-<?= strtolower($row['status_pembayaran']) ?>">
                                                    <?= $row['status_pembayaran'] === 'Lunas' ? '✓ Lunas' : '⚠ Hutang' ?>
                                                </span>
                                            </td>
                                            <td><span class="badge-diterima">✓ Diterima</span></td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <?php if (!$hasDiterima): ?>
                                        <tr><td colspan="10" style="text-align:center;padding:24px;color:var(--muted)">Belum ada barang yang diterima.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div><!-- end tab-masuk -->

            <!-- ══════════════════════════════════════════
                 TAB 2: STOK KELUAR
            ═══════════════════════════════════════════ -->
            <div id="tab-keluar" style="padding:20px;display:none;flex-direction:column;gap:16px;">

                <!-- Sub-tab -->
                <div class="subtab-bar">
                    <button class="subtab-btn active" onclick="switchSubTab('keluar','form',this)">
                        <i class="fas fa-plus-circle"></i> Catat Stok Keluar
                    </button>
                    <button class="subtab-btn" onclick="switchSubTab('keluar','expired',this)">
                        <i class="fas fa-calendar-times"></i> Obat Expired
                        <?php if ($jumlahExpired > 0): ?>
                            <span class="tab-counter" style="font-size:10.5px"><?= $jumlahExpired ?></span>
                        <?php endif; ?>
                    </button>
                    <button class="subtab-btn" onclick="switchSubTab('keluar','riwayat',this)">
                        <i class="fas fa-history"></i> Riwayat
                    </button>
                </div>

                <!-- Sub: Form Stok Keluar -->
                <div id="subtab-keluar-form">
                    <div class="form-grid">
                        <div class="form-card">
                            <h3><i class="fas fa-arrow-up" style="color:#dc2626"></i> Catat Stok Keluar</h3>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Tanggal</label>
                                    <input type="date" id="fk-tanggal" class="form-input" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Obat</label>
                                    <select id="fk-obat" class="form-select" onchange="loadStokHint()">
                                        <option value="">-- Pilih Obat --</option>
                                        <?php
                                        mysqli_data_seek($obatResult, 0);
                                        while ($o = mysqli_fetch_assoc($obatResult)):
                                        ?>
                                            <option value="<?= $o['id_obat'] ?>" data-stok="<?= $o['stok'] ?>">
                                                <?= htmlspecialchars($o['nama_obat']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="stok-hint" id="stok-hint">
                                <i class="fas fa-info-circle" style="color:var(--primary)"></i>
                                Stok tersedia: <strong id="stok-hint-val">0</strong> pcs
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Jumlah (pcs)</label>
                                    <input type="number" id="fk-jumlah" class="form-input" placeholder="0" min="1">
                                </div>
                                <div class="form-group">
                                    <label>Keterangan</label>
                                    <select id="fk-keterangan" class="keterangan-select">
                                        <option value="Rusak">Rusak</option>
                                        <option value="Hilang">Hilang</option>
                                        <option value="Retur">Retur ke Supplier</option>
                                        <option value="Penyesuaian">Penyesuaian Stok</option>
                                        <option value="Lainnya">Lainnya</option>
                                    </select>
                                </div>
                            </div>

                            <button class="btn-full btn-primary" onclick="submitKeluar()">
                                <i class="fas fa-save"></i> Simpan Stok Keluar
                            </button>
                        </div>

                        <!-- Info card kanan -->
                        <div style="display:flex;flex-direction:column;gap:20px;">
                            <div class="form-card" style="background:linear-gradient(135deg,#f0fdf4,#fff)">
                                <h3><i class="fas fa-info-circle" style="color:#16a34a"></i> Panduan</h3>
                                <div style="font-size:13.5px;color:var(--muted);line-height:1.8">
                                    Gunakan form ini untuk mencatat stok keluar yang bukan dari penjualan:
                                    <div class="alert-list" style="margin-top:10px">
                                        <div class="alert-item"><span><i class="fas fa-ban" style="color:#dc2626;margin-right:6px"></i>Obat rusak</span></div>
                                        <div class="alert-item"><span><i class="fas fa-undo" style="color:#f59e0b;margin-right:6px"></i>Retur ke supplier</span></div>
                                        <div class="alert-item"><span><i class="fas fa-search" style="color:#3b82f6;margin-right:6px"></i>Hilang saat opname</span></div>
                                        <div class="alert-item"><span><i class="fas fa-sliders-h" style="color:#8b5cf6;margin-right:6px"></i>Penyesuaian stok</span></div>
                                    </div>
                                    <p style="margin:10px 0 0;font-size:12px">Untuk obat expired, gunakan tab <strong>Obat Expired</strong>.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sub: Obat Expired -->
                <div id="subtab-keluar-expired" style="display:none;flex-direction:column;gap:12px;">
                    <?php if ($jumlahExpired > 0): ?>
                        <div class="expired-alert-banner">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span><strong><?= $jumlahExpired ?> item obat</strong> telah melewati tanggal expired. Segera lakukan penghapusan stok.</span>
                        </div>
                    <?php endif; ?>
                    <div class="history-card">
                        <div class="history-card-header" style="background:linear-gradient(135deg,#fef2f2,#fff1f1)">
                            <i class="fas fa-calendar-times" style="color:#dc2626"></i> Daftar Obat Expired
                        </div>
                        <div style="overflow-x:auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>No</th><th>Nama Obat</th><th>Batch</th>
                                        <th>Expired Date</th><th>Lewat</th><th>Jumlah</th><th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $no = 1;
                                    $hasExp = false;
                                    mysqli_data_seek($expiredResult, 0);
                                    while ($row = mysqli_fetch_assoc($expiredResult)):
                                        $hasExp  = true;
                                        $selisih = (int)(( strtotime(date('Y-m-d')) - strtotime($row['expired_date']) ) / 86400);
                                    ?>
                                        <tr id="exprow-<?= $row['id_pembelian'] ?>">
                                            <td><?= $no++ ?></td>
                                            <td style="font-weight:600"><?= htmlspecialchars($row['nama_obat']) ?></td>
                                            <td><span style="background:var(--bg);padding:2px 8px;border-radius:6px;font-size:12px"><?= htmlspecialchars($row['batch'] ?? '-') ?></span></td>
                                            <td style="color:#dc2626;font-weight:600"><?= $row['expired_date'] ?></td>
                                            <td><span class="badge-expired-k">+<?= $selisih ?> hari</span></td>
                                            <td><?= $row['jumlah'] ?> pcs</td>
                                            <td>
                                                <button class="btn-buang" onclick="openBuangModal(<?= $row['id_pembelian'] ?>, <?= $row['id_obat'] ?>, '<?= addslashes($row['nama_obat']) ?>', <?= $row['jumlah'] ?>, '<?= $row['expired_date'] ?>')">
                                                    <i class="fas fa-trash-alt"></i> Buang
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <?php if (!$hasExp): ?>
                                        <tr>
                                            <td colspan="7" style="text-align:center;padding:28px;color:var(--muted)">
                                                <i class="fas fa-check-circle" style="font-size:28px;color:#22c55e;display:block;margin-bottom:8px"></i>
                                                Tidak ada obat expired saat ini 🎉
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Sub: Riwayat -->
                <div id="subtab-keluar-riwayat" style="display:none">
                    <div class="history-card">
                        <div class="history-card-header">
                            <i class="fas fa-history"></i> Riwayat Stok Keluar
                        </div>
                        <div style="overflow-x:auto">
                            <table class="data-table">
                                <thead>
                                    <tr><th>No</th><th>Nama Obat</th><th>Jumlah</th><th>Tanggal</th><th>Keterangan</th></tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $no = 1; $hasRw = false;
                                    while ($d = mysqli_fetch_assoc($riwayatKeluar)):
                                        $hasRw = true;
                                        $ket   = $d['keterangan'];
                                        $cls   = match($ket) {
                                            'Expired'       => 'expired-k',
                                            'Retur'         => 'retur',
                                            default         => 'keluar'
                                        };
                                    ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td style="font-weight:600"><?= htmlspecialchars($d['nama_obat']) ?></td>
                                            <td><?= $d['jumlah'] ?> pcs</td>
                                            <td><?= $d['tanggal'] ?></td>
                                            <td><span class="badge-<?= $cls ?>"><?= htmlspecialchars($ket) ?></span></td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <?php if (!$hasRw): ?>
                                        <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--muted)">Belum ada riwayat stok keluar.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div><!-- end tab-keluar -->

        </div><!-- end main-content -->
    </div>

    <!-- ════ MODAL: Konfirmasi Terima ════ -->
    <div class="modal-overlay" id="modal-terima">
        <div class="modal-box" style="text-align:center">
            <div class="modal-icon green"><i class="fas fa-box-open"></i></div>
            <div class="modal-title">Konfirmasi Penerimaan</div>
            <div class="modal-subtitle">Pastikan barang sudah diterima secara fisik sebelum konfirmasi.</div>
            <div class="modal-info-row" style="margin-top:16px">
                <span>Nama Obat</span>
                <span id="terima-nama" style="font-weight:700">—</span>
            </div>
            <div class="modal-info-row">
                <span>Supplier</span>
                <span id="terima-supplier">—</span>
            </div>
            <div class="modal-info-row">
                <span>Jumlah Masuk</span>
                <span id="terima-jumlah" style="color:var(--green);font-weight:700">—</span>
            </div>
            <div class="modal-footer">
                <button class="modal-btn secondary" onclick="closeModal('modal-terima')">Batal</button>
                <button class="modal-btn primary" onclick="submitTerima()">
                    <i class="fas fa-check"></i> Ya, Barang Diterima
                </button>
            </div>
        </div>
    </div>

    <!-- ════ MODAL: Buang Expired ════ -->
    <div class="modal-overlay" id="modal-buang">
        <div class="modal-box" style="text-align:center">
            <div class="modal-icon red"><i class="fas fa-trash-alt"></i></div>
            <div class="modal-title">Buang Obat Expired?</div>
            <div class="modal-subtitle">Stok akan dikurangi secara permanen. Tindakan tidak dapat dibatalkan.</div>
            <div class="modal-info-row" style="margin-top:16px">
                <span>Nama Obat</span>
                <span id="buang-nama" style="font-weight:700">—</span>
            </div>
            <div class="modal-info-row">
                <span>Expired Date</span>
                <span id="buang-expired" style="color:#dc2626;font-weight:600">—</span>
            </div>
            <div class="modal-info-row">
                <span>Jumlah Dibuang</span>
                <span id="buang-jumlah" style="font-weight:700">—</span>
            </div>
            <div class="modal-footer">
                <button class="modal-btn secondary" onclick="closeModal('modal-buang')">Batal</button>
                <button class="modal-btn primary" style="background:#dc2626" onclick="submitBuang()">
                    <i class="fas fa-trash-alt"></i> Ya, Buang
                </button>
            </div>
        </div>
    </div>

    <!-- ════ MODAL: Sukses ════ -->
    <div class="modal-overlay" id="modal-sukses">
        <div class="modal-box" style="text-align:center">
            <div class="modal-icon green"><i class="fas fa-check"></i></div>
            <div class="modal-title">Berhasil!</div>
            <div class="modal-subtitle" id="modal-sukses-text">Operasi berhasil.</div>
            <div class="modal-footer">
                <button class="modal-btn primary" onclick="closeModal('modal-sukses')">Oke</button>
            </div>
        </div>
    </div>

    <!-- ════ MODAL: Error ════ -->
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

    <div class="toast" id="toast"></div>

    <script>
        // ── Main Tab ──
        function switchTab(tab, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-masuk').style.display  = tab === 'masuk'  ? 'flex'  : 'none';
            document.getElementById('tab-keluar').style.display = tab === 'keluar' ? 'flex'  : 'none';
        }

        // ── Sub Tab ──
        function switchSubTab(mainTab, subTab, btn) {
            // deactivate all subtab-btns in same group
            btn.closest('.subtab-bar').querySelectorAll('.subtab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const map = {
                masuk:  ['menunggu','diterima'],
                keluar: ['form','expired','riwayat']
            };
            map[mainTab].forEach(s => {
                const el = document.getElementById(`subtab-${mainTab}-${s}`);
                el.style.display = (s === subTab) ? (s === 'keluar-form' ? 'block' : 'block') : 'none';
            });
        }

        // ── Stok hint ──
        function loadStokHint() {
            const sel = document.getElementById('fk-obat');
            const opt = sel.options[sel.selectedIndex];
            const el  = document.getElementById('stok-hint');
            if (!opt.value) { el.classList.remove('show'); return; }
            document.getElementById('stok-hint-val').textContent = opt.getAttribute('data-stok');
            el.classList.add('show');
        }

        // ── Konfirmasi Terima ──
        let activeTerimaId = null;

        function openTerimaModal(id, nama, jumlah, supplier) {
            activeTerimaId = id;
            document.getElementById('terima-nama').textContent     = nama;
            document.getElementById('terima-supplier').textContent = supplier;
            document.getElementById('terima-jumlah').textContent   = '+' + jumlah + ' pcs';
            openModal('modal-terima');
        }

        function submitTerima() {
            const fd = new FormData();
            fd.append('ajax_terima', '1');
            fd.append('id_pembelian', activeTerimaId);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    closeModal('modal-terima');
                    if (data.success) {
                        document.getElementById('modal-sukses-text').textContent = data.message;
                        openModal('modal-sukses');
                        setTimeout(() => { closeModal('modal-sukses'); location.reload(); }, 1800);
                    } else {
                        document.getElementById('modal-error-text').textContent = data.message;
                        openModal('modal-error');
                    }
                })
                .catch(() => { document.getElementById('modal-error-text').textContent = 'Koneksi gagal.'; openModal('modal-error'); });
        }

        // ── Stok Keluar Manual ──
        function submitKeluar() {
            const tanggal    = document.getElementById('fk-tanggal').value;
            const id_obat    = document.getElementById('fk-obat').value;
            const jumlah     = document.getElementById('fk-jumlah').value;
            const keterangan = document.getElementById('fk-keterangan').value;

            if (!tanggal || !id_obat || !jumlah || jumlah <= 0) {
                showToast('Harap isi semua field!', true); return;
            }

            const fd = new FormData();
            fd.append('ajax_keluar', '1');
            fd.append('tanggal', tanggal); fd.append('id_obat', id_obat);
            fd.append('jumlah', jumlah);   fd.append('keterangan', keterangan);

            fetch(window.location.href, { method: 'POST', body: fd })
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
                .catch(() => { document.getElementById('modal-error-text').textContent = 'Koneksi gagal.'; openModal('modal-error'); });
        }

        // ── Buang Expired ──
        let activeBuangData = {};

        function openBuangModal(id_pembelian, id_obat, nama, jumlah, expired) {
            activeBuangData = { id_pembelian, id_obat, jumlah };
            document.getElementById('buang-nama').textContent    = nama;
            document.getElementById('buang-expired').textContent = expired;
            document.getElementById('buang-jumlah').textContent  = jumlah + ' pcs';
            openModal('modal-buang');
        }

        function submitBuang() {
            const fd = new FormData();
            fd.append('ajax_buang', '1');
            fd.append('id_pembelian', activeBuangData.id_pembelian);
            fd.append('id_obat',      activeBuangData.id_obat);
            fd.append('jumlah',       activeBuangData.jumlah);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    closeModal('modal-buang');
                    if (data.success) {
                        document.getElementById('modal-sukses-text').textContent = data.message;
                        openModal('modal-sukses');
                        setTimeout(() => { closeModal('modal-sukses'); location.reload(); }, 1800);
                    } else {
                        document.getElementById('modal-error-text').textContent = data.message;
                        openModal('modal-error');
                    }
                })
                .catch(() => { document.getElementById('modal-error-text').textContent = 'Koneksi gagal.'; openModal('modal-error'); });
        }

        // ── Modal helpers ──
        function openModal(id)  { document.getElementById(id).classList.add('show'); }
        function closeModal(id) { document.getElementById(id).classList.remove('show'); }

        // ── Toast ──
        function showToast(msg, error = false) {
            const t = document.getElementById('toast');
            t.innerHTML = `<i class="fas fa-${error?'exclamation-circle':'check-circle'}"></i> ${msg}`;
            t.className = 'toast show' + (error ? ' error' : '');
            setTimeout(() => t.className = 'toast', 2800);
        }

        // ── Dropdown user ──
        function toggleDropdown() {
            const m = document.getElementById('ddmenu');
            m.style.display = m.style.display === 'block' ? 'none' : 'block';
        }
        document.addEventListener('click', e => {
            if (!document.getElementById('ddwrap').contains(e.target))
                document.getElementById('ddmenu').style.display = 'none';
        });
    </script>
</body>
</html>