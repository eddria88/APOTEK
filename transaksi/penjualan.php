<?php
session_start();
require_once "../koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SESSION['role'] != "kasir" && $_SESSION['role'] != "owner") {
    header("Location: ../dashboard.php");
    exit;
}

$username  = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user      = mysqli_fetch_assoc($queryUser);
$isOwner   = $user['role'] === 'owner';

// ── AJAX: Tambah Member Baru ──
if (isset($_POST['ajax_tambah_member'])) {
    header('Content-Type: application/json');
    if ($isOwner) {
        echo json_encode(['success' => false, 'message' => 'Owner tidak memiliki izin mengubah data.']);
        exit;
    }
    $nama   = mysqli_real_escape_string($conn, trim($_POST['nama_lengkap'] ?? ''));
    $hp     = mysqli_real_escape_string($conn, trim($_POST['no_hp'] ?? ''));
    $alamat = mysqli_real_escape_string($conn, trim($_POST['alamat'] ?? ''));
    if (!$nama || !$hp) {
        echo json_encode(['success' => false, 'message' => 'Nama dan No. HP wajib diisi!']);
        exit;
    }
    $ok = mysqli_query($conn, "INSERT INTO member (nama_lengkap, no_hp, alamat) VALUES ('$nama','$hp','$alamat')");
    $id = mysqli_insert_id($conn);
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Member berhasil didaftarkan!', 'id' => $id, 'nama' => htmlspecialchars($nama), 'no_hp' => htmlspecialchars($hp)]
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// ── AJAX: Simpan Transaksi ──
if (isset($_POST['ajax_transaksi'])) {
    header('Content-Type: application/json');
    if ($isOwner) {
        echo json_encode(['success' => false, 'message' => 'Owner tidak memiliki izin mengubah data.']);
        exit;
    }
    $tanggal   = date("Y-m-d H:i:s");
    $items     = json_decode($_POST['items'], true);
    $bayar     = (float) $_POST['bayar'];
    $metode    = $_POST['metode'];
    $total     = (float) $_POST['total'];
    $kembalian = $bayar - $total;

    if ($kembalian < 0 && $metode === 'cash') {
        echo json_encode(['success' => false, 'message' => 'Uang bayar kurang!']);
        exit;
    }

    $metodeMap = ['cash' => 'Tunai', 'transfer' => 'Tranfer_Bank', 'ewallet' => 'E_Wallet'];
    $metode_db = $metodeMap[$metode] ?? 'Tunai';

    $id_member_trx = (isset($_POST['id_member']) && $_POST['id_member'] !== '') ? (int)$_POST['id_member'] : null;
    $id_member_sql = $id_member_trx ? "'$id_member_trx'" : 'NULL';
    mysqli_query(
        $conn,
        "INSERT INTO penjualan (tanggal, total, bayar, kembalian, metode_pembayaran, id_user, id_member)
         VALUES ('$tanggal','$total','$bayar','$kembalian','$metode_db','{$user['id_user']}',$id_member_sql)"
    );
    $id_penjualan = mysqli_insert_id($conn);

    foreach ($items as $item) {
        $id_obat  = (int)$item['id_obat'];
        $jumlah   = (int)$item['jumlah'];
        $harga    = (float)$item['harga'];
        $subtotal = $jumlah * $harga;
        $cek = mysqli_fetch_assoc(mysqli_query($conn, "SELECT stok FROM obat WHERE id_obat='$id_obat'"));
        if (!$cek || $cek['stok'] < $jumlah) {
            echo json_encode(['success' => false, 'message' => "Stok {$item['nama']} tidak cukup!"]);
            exit;
        }
        mysqli_query($conn, "INSERT INTO detail_penjualan (id_penjualan,id_obat,jumlah,harga_jual,subtotal) VALUES ('$id_penjualan','$id_obat','$jumlah','$harga','$subtotal')");
        $sisa = $jumlah;

        $q = mysqli_query($conn, "SELECT * FROM pembelian WHERE id_obat='$id_obat' AND stok_sisa > 0 ORDER BY expired_date ASC, id_pembelian ASC");

        while (($row = mysqli_fetch_assoc($q)) && $sisa > 0) {
            $stok_batch = $row['jumlah'];
            $ambil = min($stok_batch, $sisa);
            mysqli_query($conn, "UPDATE pembelian SET stok_sisa = stok_sisa - $ambil WHERE id_pembelian='{$row['id_pembelian']}'");
            mysqli_query($conn, "
                INSERT INTO stok_keluar (id_obat,tanggal,jumlah,keterangan)
                VALUES ('$id_obat', NOW(), '$ambil', 'Penjualan ID $id_penjualan')
            ");
            $sisa -= $ambil;
        }

        mysqli_query($conn, "UPDATE obat SET stok = stok - $jumlah WHERE id_obat='$id_obat'");
    }

    echo json_encode(['success' => true, 'id_penjualan' => $id_penjualan, 'kembalian' => $kembalian, 'tanggal' => $tanggal, 'total' => $total, 'bayar' => $bayar]);
    exit;
}

// ── Fetch data ──
// Ambil SEMUA obat termasuk yang stok = 0, untuk menampilkan badge "Habis"
$uploadUrl = '../uploads/obat/';

$obatResult = mysqli_query($conn, "SELECT o.*, k.nama_kategori FROM obat o LEFT JOIN kategori k ON o.id_kategori=k.id_kategori ORDER BY o.nama_obat ASC");

$kategoriResult = mysqli_query($conn, "SELECT DISTINCT k.id_kategori, k.nama_kategori FROM kategori k INNER JOIN obat o ON k.id_kategori=o.id_kategori WHERE o.stok > 0 ORDER BY k.nama_kategori ASC");
$kategoriList = [];
while ($k = mysqli_fetch_assoc($kategoriResult)) $kategoriList[] = $k;
$obatList = [];
while ($row = mysqli_fetch_assoc($obatResult)) $obatList[] = $row;

$memberResult = mysqli_query($conn, "SELECT id_member, nama_lengkap, no_hp FROM member ORDER BY nama_lengkap ASC");
$memberList = [];
while ($row = mysqli_fetch_assoc($memberResult)) $memberList[] = $row;

$historyResult = mysqli_query(
    $conn,
    "SELECT p.*, u.nama_user, m.nama_lengkap as nama_member
     FROM penjualan p
     LEFT JOIN users u ON p.id_user=u.id_user
     LEFT JOIN member m ON p.id_member=m.id_member
     ORDER BY p.id_penjualan DESC LIMIT 20"
);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Kasir / Transaksi — Apotek</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/penjualan.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* ── Animasi modal transfer (override skala default) ── */
        #modal-transfer .modal-box,
        #modal-ewallet .modal-box {
            transform: translateY(28px) scale(0.96);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            width: 440px;
        }

        #modal-transfer.show .modal-box,
        #modal-ewallet.show .modal-box {
            transform: translateY(0) scale(1);
        }

        /* ── Baris info rekening ── */
        .mt-info-row {
            display: flex;
            align-items: center;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13.5px;
            gap: 8px;
        }

        .mt-info-row:last-child {
            border-bottom: none;
        }

        .mt-info-label {
            width: 82px;
            flex-shrink: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .mt-info-sep {
            color: var(--muted);
            flex-shrink: 0;
            margin-right: 4px;
        }

        .mt-info-val {
            font-weight: 700;
            color: var(--text);
            font-size: 14.5px;
        }

        .mt-info-val.mono {
            font-family: 'DM Mono', monospace, monospace;
            letter-spacing: 0.5px;
        }

        /* ── Total baris ── */
        .mt-total-row {
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 8px;
            margin: 4px 0 22px;
        }

        .mt-total-label {
            font-size: 15px;
            color: var(--muted);
        }

        .mt-total-val {
            font-size: 24px;
            font-weight: 800;
            color: var(--green);
        }

        /* ══════════════════════════════════════
           STOK HABIS — Card overlay & badge
        ══════════════════════════════════════ */
        .product-card.out-of-stock {
            position: relative;
            opacity: 0.72;
            cursor: default !important;
            pointer-events: none; /* nonaktifkan klik normal */
        }

        /* Kita butuh pointer-events aktif hanya untuk tombol pesan */
        .product-card.out-of-stock {
            pointer-events: all;
        }

        .product-card.out-of-stock::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.55);
            border-radius: inherit;
            pointer-events: none;
        }

        .badge-habis {
            position: absolute;
            top: 8px;
            left: 8px;
            background: #ef4444;
            color: #fff;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 20px;
            letter-spacing: .3px;
            z-index: 2;
        }

        .btn-pesan-card {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            width: 100%;
            margin-top: 6px;
            padding: 6px 0;
            background: linear-gradient(135deg, #f97316, #ef4444);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            position: relative;
            z-index: 3;
            transition: opacity .15s;
        }

        .btn-pesan-card:hover {
            opacity: .88;
        }

        /* ══════════════════════════════════════
           MODAL PESAN — Stok Habis & Stok Kurang
        ══════════════════════════════════════ */
        #modal-pesan-habis .modal-box,
        #modal-pesan-kurang .modal-box {
            width: 420px;
            text-align: center;
        }

        .modal-icon-circ.orange {
            background: #fff7ed;
            color: #f97316;
        }

        .pesan-obat-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            margin: 8px 0 4px;
        }

        .pesan-info-box {
            background: #fff7ed;
            border: 1.5px solid #fed7aa;
            border-radius: 10px;
            padding: 10px 14px;
            margin: 12px 0 18px;
            font-size: 13px;
            color: #92400e;
            line-height: 1.55;
        }

        .pesan-info-box strong {
            color: #c2410c;
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="topnav">
        <a href="../dashboard.php" class="sb-brand">
            <img src="../uploads/logo.png" alt="Logo Apotek" style="height: 50px;" class="logo">
        </a>
        <div class="breadcrumb">
            <i class="fas fa-chevron-right"></i>
            <span class="current">Penjualan</span>
        </div>
        <div class="topnav-right">
            <div class="user-info" class="ddwrap" id="ddwrap" onclick="toggleDropdown()">
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
                <a class="sb-link" href="pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
                <a class="sb-link active" href="penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
                <a class="sb-link" href="pesanan.php"><i class="fas fa-box"></i> Pesanan</a>
                <div class="sb-sec">Laporan</div>
                <a class="sb-link" href="../laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
                <a class="sb-link" href="../laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
                <a class="sb-link" href="../laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
            <?php elseif ($user['role'] == 'admin'): ?>
                <div class="sb-sec">Transaksi</div>
                <a class="sb-link" href="pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
            <?php elseif ($user['role'] == 'kasir'): ?>
                <div class="sb-sec">Transaksi</div>
                <a class="sb-link active" href="penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
                <a class="sb-link" href="pesanan.php"><i class="fas fa-box"></i> Pesanan</a>
            <?php endif; ?>
            <div class="sb-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="main-content">

            <?php if (!$isOwner): ?>
                <div class="tabs-bar">
                    <button class="tab-btn active" onclick="switchTab('pos',this)"><i class="fas fa-cash-register"></i> Kasir / Transaksi</button>
                    <button class="tab-btn" onclick="switchTab('history',this)"><i class="fas fa-history"></i> Riwayat Penjualan</button>
                </div>
            <?php endif; ?>

            <!-- POS -->
            <div id="tab-pos" class="pos-view" style="<?= $isOwner ? 'display:none;' : '' ?>">

                <div class="pos-products">

                    <!-- Search -->
                    <div class="search-card">
                        <div class="search-box">
                            <i class="fas fa-barcode"></i>
                            <input type="text" id="search-product" placeholder="Scan barcode atau ketik nama obat..." oninput="filterProducts(this.value)" <?= $isOwner ? 'disabled' : '' ?>>
                        </div>
                        <div class="cat-filter" id="cat-filter-wrap">
                            <button class="btn-filter active" onclick="filterCat(this,'Semua')">Semua</button>
                            <?php foreach ($kategoriList as $kat): ?>
                                <button class="btn-filter"
                                    onclick="filterCat(this,'<?= addslashes(htmlspecialchars($kat['nama_kategori'])) ?>')">
                                    <?= htmlspecialchars($kat['nama_kategori']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="product-grid" id="product-grid"></div>
                </div>

                <!-- Cart -->
                <div class="pos-cart">
                    <div class="cart-header"><i class="fas fa-shopping-cart"></i> Keranjang</div>
                    <div class="cart-items" id="cart-items">
                        <div class="empty-cart"><i class="fas fa-cart-plus"></i>
                            <p>Keranjang kosong</p>
                            <p style="font-size:11px;color:#c0cfc0">Pilih produk di kiri</p>
                        </div>
                    </div>
                    <div class="cart-footer">
                        <!-- Member -->
                        <div style="border-bottom:1px solid var(--border);padding-bottom:10px;margin-bottom:2px">
                            <span class="pay-label" style="margin-bottom:6px"><i class="fas fa-user-tag" style="color:var(--purple)"></i> Member</span>
                            <div id="member-picker">
                                <div style="display:flex;gap:6px">
                                    <select id="member-sel" class="sel-inp" style="font-size:12.5px;padding:7px 10px;flex:1" onchange="selectMember(this.value)" <?= $isOwner ? 'disabled' : '' ?>>
                                        <option value="">— Tanpa Member —</option>
                                        <?php foreach ($memberList as $m): ?>
                                            <option value="<?= $m['id_member'] ?>"
                                                data-nama="<?= htmlspecialchars($m['nama_lengkap']) ?>"
                                                data-hp="<?= htmlspecialchars($m['no_hp']) ?>">
                                                <?= htmlspecialchars($m['nama_lengkap']) ?> — <?= htmlspecialchars($m['no_hp']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn-new-member" style="padding:7px 10px;font-size:12px" onclick="openModal('modal-reg-member')" title="Daftar member baru" <?= $isOwner ? 'disabled' : '' ?>>
                                        <i class="fas fa-user-plus"></i>
                                    </button>
                                </div>
                                <p style="font-size:11.5px;color:var(--muted);margin-top:5px"><i class="fas fa-tag" style="color:var(--purple)"></i> Member diskon <strong>1–5%</strong> otomatis</p>
                            </div>
                            <div id="member-active" class="hidden">
                                <div class="member-active" style="padding:8px 12px">
                                    <div class="member-av" id="m-av" style="width:30px;height:30px;font-size:12px">A</div>
                                    <div class="member-info" style="flex:1">
                                        <div class="mn" id="m-name" style="font-size:13px">—</div>
                                        <div class="mhp" id="m-hp" style="font-size:11px">—</div>
                                    </div>
                                    <div class="disc-badge" id="m-disc" style="font-size:11px;padding:2px 8px">Diskon 1%</div>
                                    <button class="btn-rm-member" onclick="clearMember()" title="Hapus member"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>

                        <div class="sum-row"><span>Subtotal</span><span id="s-subtotal">Rp 0</span></div>
                        <div class="sum-row disc hidden" id="disc-row">
                            <span id="disc-lbl" style="color:var(--purple)">Diskon Member (1%)</span>
                            <span id="disc-val" style="color:var(--purple)">- Rp 0</span>
                        </div>
                        <div class="sum-row total"><span>Total</span><span id="s-total">Rp 0</span></div>

                        <div>
                            <span class="pay-label">Metode Pembayaran</span>
                            <select id="pay-method" class="sel-inp" onchange="togglePayFields(this.value)" <?= $isOwner ? 'disabled' : '' ?>>
                                <option value="cash">💵 Tunai</option>
                                <option value="transfer">🏦 Transfer Bank</option>
                                <option value="ewallet">📱 E-Wallet</option>
                            </select>
                        </div>

                        <!-- Pilih Bank -->
                        <div id="bank-sec" style="display:none;flex-direction:column;gap:4px">
                            <span class="pay-label">Pilih Bank</span>
                            <select id="bank-sel" class="sel-inp" onchange="updatePayBtn()" <?= $isOwner ? 'disabled' : '' ?>>
                                <option value="">-- Pilih Bank --</option>
                                <option value="BCA">Bank BCA</option>
                                <option value="BRI">Bank BRI</option>
                                <option value="Mandiri">Bank Mandiri</option>
                                <option value="BNI">Bank BNI</option>
                            </select>
                        </div>

                        <!-- Pilih E-Wallet -->
                        <div id="ewallet-sec" style="display:none;flex-direction:column;gap:4px">
                            <span class="pay-label">Pilih Payment</span>
                            <select id="ewallet-sel" class="sel-inp" onchange="updatePayBtn()" <?= $isOwner ? 'disabled' : '' ?>>
                                <option value="">-- Pilih Payment --</option>
                                <option value="Dana">Dana</option>
                                <option value="OVO">OVO</option>
                                <option value="GoPay">GoPay</option>
                                <option value="ShopeePay">ShopeePay</option>
                                <option value="LinkAja">LinkAja</option>
                            </select>
                        </div>

                        <div id="cash-sec" class="cash-wrap">
                            <span class="pay-label">Uang Bayar</span>
                            <input type="number" id="cash-in" class="cash-inp" placeholder="Masukkan nominal..." oninput="calcKemb()" <?= $isOwner ? 'disabled' : '' ?>>
                            <div class="kemb-row hidden" id="kemb-row">
                                <span>Kembalian</span>
                                <span id="kemb-val">Rp 0</span>
                            </div>
                        </div>

                        <button class="btn-pay" id="btn-pay" onclick="confirmTrx()" disabled <?= $isOwner ? 'title="Owner tidak dapat input transaksi"' : '' ?>>
                            <i class="fas fa-check-circle"></i> <?= $isOwner ? 'Monitoring' : 'Proses Pembayaran' ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- History -->
            <div id="tab-history" class="history-view" style="<?= $isOwner ? 'display:flex;' : '' ?>">
                <div class="history-card">
                    <div class="history-card-hdr"><i class="fas fa-table"></i> Riwayat Penjualan Terbaru</div>
                    <div style="overflow-x:auto">
                        <table class="dt">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Tanggal</th>
                                    <th>Kasir</th>
                                    <th>Member</th>
                                    <th>Metode</th>
                                    <th>Total</th>
                                    <th>Bayar</th>
                                    <th>Kembalian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                $ml = ['Tunai' => ['Tunai', 'bm-tunai'], 'Tranfer_Bank' => ['Transfer Bank', 'bm-transfer'], 'E_Wallet' => ['E-Wallet', 'bm-ewallet']];
                                while ($row = mysqli_fetch_assoc($historyResult)):
                                    $mt = $row['metode_pembayaran'] ?? '';
                                    $m = $ml[$mt] ?? [$mt ?: '-', 'bm-tunai'];
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td style="color:var(--muted);font-size:13px"><?= $row['tanggal'] ?></td>
                                        <td><?= htmlspecialchars($row['nama_user'] ?? '-') ?></td>
                                        <td><?php if (!empty($row['nama_member'])): ?>
                                                <span style="display:inline-flex;align-items:center;gap:5px;background:var(--purple-pale);color:var(--purple);border-radius:20px;padding:2px 10px;font-size:11.5px;font-weight:700">
                                                    <i class="fas fa-user-tag"></i> <?= htmlspecialchars($row['nama_member']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:var(--muted);font-size:12px">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="bm <?= $m[1] ?>"><?= $m[0] ?></span></td>
                                        <td style="font-weight:700;color:var(--green)">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>
                                        <td>Rp <?= number_format($row['bayar'], 0, ',', '.') ?></td>
                                        <td>Rp <?= number_format($row['kembalian'], 0, ',', '.') ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         MODAL: Daftar Member Baru
    ════════════════════════════════════════════ -->
    <div class="modal-overlay" id="modal-reg-member">
        <div class="modal-box" style="text-align:left">
            <div class="modal-icon-circ purple" style="margin-bottom:12px"><i class="fas fa-user-plus"></i></div>
            <div class="modal-ttl" style="text-align:center">Daftar Member Baru</div>
            <p style="text-align:center;font-size:13px;color:var(--muted);margin-bottom:20px">Isi data berikut untuk mendaftarkan member</p>
            <div class="mfg">
                <label>Nama Lengkap <span style="color:var(--red)">*</span></label>
                <input type="text" id="nm-nama" class="mfi" placeholder="Masukkan nama lengkap">
            </div>
            <div class="mfg">
                <label>No. HP <span style="color:var(--red)">*</span></label>
                <input type="text" id="nm-hp" class="mfi" placeholder="08xxxxxxxxxx">
            </div>
            <div class="mfg">
                <label>Alamat <span style="color:var(--muted);font-weight:400">(opsional)</span></label>
                <input type="text" id="nm-alamat" class="mfi" placeholder="Alamat member">
            </div>
            <div class="modal-ft">
                <button class="mbtn secondary" onclick="closeModal('modal-reg-member')">Batal</button>
                <button class="mbtn purple" onclick="submitRegMember()"><i class="fas fa-save"></i> Daftarkan</button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         MODAL: Konfirmasi Tunai / E-Wallet
    ════════════════════════════════════════════ -->
    <div class="modal-overlay" id="modal-confirm">
        <div class="modal-box">
            <div class="modal-icon-circ green"><i class="fas fa-receipt"></i></div>
            <div class="modal-ttl">Konfirmasi Pembayaran</div>
            <div class="modal-sub" id="confirm-body">Proses transaksi ini?</div>
            <div class="modal-ft">
                <button class="mbtn secondary" onclick="closeModal('modal-confirm')">Batal</button>
                <button class="mbtn primary" onclick="processTrx()">Ya, Proses</button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         MODAL: Konfirmasi Transfer Bank
    ════════════════════════════════════════════ -->
    <div class="modal-overlay" id="modal-transfer">
        <div class="modal-box">
            <div class="modal-icon-circ green" style="width:72px;height:72px;font-size:28px;margin-bottom:14px;">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="modal-ttl">Konfirmasi Pembayaran</div>
            <div class="modal-sub" style="margin-bottom:20px;">
                Pastikan transfer ke rekening berikut sebelum memproses
            </div>
            <div style="background:var(--bg);border-radius:12px;padding:12px 16px;margin-bottom:16px;text-align:left;">
                <div class="mt-info-row">
                    <span class="mt-info-label">Bank</span>
                    <span class="mt-info-sep">:</span>
                    <span class="mt-info-val" id="mt-bank">—</span>
                </div>
                <div class="mt-info-row">
                    <span class="mt-info-label">No. Rek</span>
                    <span class="mt-info-sep">:</span>
                    <span class="mt-info-val mono" id="mt-norek">—</span>
                </div>
                <div class="mt-info-row">
                    <span class="mt-info-label">A/N</span>
                    <span class="mt-info-sep">:</span>
                    <span class="mt-info-val" id="mt-an">—</span>
                </div>
            </div>
            <div style="border-top:1.5px dashed var(--border);margin:4px 0 14px;"></div>
            <div class="mt-total-row">
                <span class="mt-total-label">Total :</span>
                <span class="mt-total-val" id="mt-total">Rp -</span>
            </div>
            <div class="modal-ft">
                <button class="mbtn secondary" onclick="closeModal('modal-transfer')">Batal</button>
                <button class="mbtn primary" onclick="processTrx()">
                    <i class="fas fa-check" style="margin-right:5px;"></i>Ya, Proses
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         MODAL: Konfirmasi E-Wallet
    ════════════════════════════════════════════ -->
    <div class="modal-overlay" id="modal-ewallet">
        <div class="modal-box">
            <div class="modal-icon-circ green" style="width:72px;height:72px;font-size:28px;margin-bottom:14px;">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="modal-ttl">Konfirmasi Pembayaran</div>
            <div class="modal-sub" style="margin-bottom:20px;">
                Pastikan transfer ke akun berikut sebelum memproses
            </div>
            <div style="background:var(--bg);border-radius:12px;padding:12px 16px;margin-bottom:16px;text-align:left;">
                <div class="mt-info-row">
                    <span class="mt-info-label">Payment</span>
                    <span class="mt-info-sep">:</span>
                    <span class="mt-info-val" id="ew-payment">—</span>
                </div>
                <div class="mt-info-row">
                    <span class="mt-info-label">No. Rek</span>
                    <span class="mt-info-sep">:</span>
                    <span class="mt-info-val mono" id="ew-norek">—</span>
                </div>
                <div class="mt-info-row">
                    <span class="mt-info-label">A/N</span>
                    <span class="mt-info-sep">:</span>
                    <span class="mt-info-val" id="ew-an">—</span>
                </div>
            </div>
            <div style="border-top:1.5px dashed var(--border);margin:4px 0 14px;"></div>
            <div class="mt-total-row">
                <span class="mt-total-label">Total :</span>
                <span class="mt-total-val" id="ew-total">Rp -</span>
            </div>
            <div class="modal-ft">
                <button class="mbtn secondary" onclick="closeModal('modal-ewallet')">Batal</button>
                <button class="mbtn primary" onclick="processTrx()">
                    <i class="fas fa-check" style="margin-right:5px;"></i>Ya, Proses
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         MODAL: STOK HABIS → Tawarkan Pesanan
         (muncul saat klik tombol "Pesan" pada
          kartu obat yang stok = 0)
    ════════════════════════════════════════════ -->
    <div class="modal-overlay" id="modal-pesan-habis">
        <div class="modal-box">
            <!-- Icon -->
            <div class="modal-icon-circ orange" style="width:68px;height:68px;font-size:26px;margin-bottom:10px;">
                <i class="fas fa-box-open"></i>
            </div>

            <!-- Judul -->
            <div class="modal-ttl" style="font-size:17px;">Stok Obat Habis</div>

            <!-- Nama obat ditampilkan dinamis -->
            <div class="pesan-obat-name" id="ph-nama">—</div>

            <!-- Info box -->
            <div class="pesan-info-box">
                <i class="fas fa-info-circle" style="margin-right:5px;color:#f97316"></i>
                Stok <strong id="ph-nama2">obat ini</strong> saat ini <strong>kosong (0)</strong>.<br>
                Apakah Anda ingin melakukan <strong>pesanan</strong> ? <br>
                akan tetapi kedatangan barang kurang lebih 1 minggu setelah pesanan dibuat.
            </div>

            <!-- Tombol -->
            <div class="modal-ft">
                <button class="mbtn secondary" onclick="closeModal('modal-pesan-habis')">
                    <i class="fas fa-times"></i> Tidak
                </button>
                <button class="mbtn" onclick="goToPesanan()"
                    style="background:linear-gradient(135deg,#f97316,#ef4444);color:#fff;border:none;">
                    <i class="fas fa-box"></i> Ya, Buat Pesanan
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         MODAL: STOK KURANG → Tawarkan Pesanan
         (muncul saat qty di cart melebihi stok)
    ════════════════════════════════════════════ -->
    <div class="modal-overlay" id="modal-pesan-kurang">
        <div class="modal-box">
            <!-- Icon -->
            <div class="modal-icon-circ orange" style="width:68px;height:68px;font-size:26px;margin-bottom:10px;">
                <i class="fas fa-exclamation-triangle"></i>
            </div>

            <!-- Judul -->
            <div class="modal-ttl" style="font-size:17px;">Stok Tidak Mencukupi</div>

            <!-- Nama obat ditampilkan dinamis -->
            <div class="pesan-obat-name" id="pk-nama">—</div>

            <!-- Info box -->
            <div class="pesan-info-box">
                <i class="fas fa-warehouse" style="margin-right:5px;color:#f97316"></i>
                Stok tersedia: <strong id="pk-stok">0</strong> unit, sedangkan yang dibutuhkan melebihi jumlah tersebut.<br><br>
                Apakah Anda ingin melakukan <strong>pesanan</strong> ? <br>
                akan tetapi kedatangan barang kurang lebih 1 minggu setelah pesanan dibuat.
            </div>

            <!-- Tombol -->
            <div class="modal-ft">
                <button class="mbtn secondary" onclick="closeModal('modal-pesan-kurang')">
                    <i class="fas fa-times"></i> Tidak
                </button>
                <button class="mbtn" onclick="goToPesanan()"
                    style="background:linear-gradient(135deg,#f97316,#ef4444);color:#fff;border:none;">
                    <i class="fas fa-box"></i> Ya, Buat Pesanan
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         MODAL: Sukses + Struk
    ════════════════════════════════════════════ -->
    <div class="modal-overlay" id="modal-success">
        <div class="modal-box" style="width:420px;text-align:left;padding:0;overflow:hidden">
            <div style="background:var(--green);color:#fff;padding:20px 24px;text-align:center">
                <div style="font-size:22px;font-weight:800;letter-spacing:-.5px">🌿 APOTEK</div>
                <div style="font-size:12px;opacity:.85;margin-top:2px">Sistem Manajemen Apotek</div>
                <div style="margin-top:10px;background:rgba(255,255,255,.15);border-radius:8px;padding:6px 12px;display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600">
                    <i class="fas fa-check-circle"></i> Transaksi Berhasil!
                </div>
            </div>
            <div id="struk-body" style="padding:20px 24px;display:flex;flex-direction:column;gap:10px"></div>
            <div style="padding:16px 24px;border-top:1px solid var(--border);display:flex;gap:10px">
                <button class="mbtn secondary" style="display:flex;align-items:center;justify-content:center;gap:6px" onclick="printStruk()">
                    <i class="fas fa-print"></i> Print Struk
                </button>
                <button class="mbtn primary" style="display:flex;align-items:center;justify-content:center;gap:6px" onclick="location.reload()">
                    <i class="fas fa-plus"></i> Transaksi Baru
                </button>
            </div>
        </div>
    </div>

    <!-- Area print struk (tersembunyi) -->
    <div id="print-area" style="display:none"></div>

    <!-- ═══════════════════════════════════════════
         MODAL: Error
    ════════════════════════════════════════════ -->
    <div class="modal-overlay" id="modal-error">
        <div class="modal-box">
            <div class="modal-icon-circ red"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="modal-ttl">Terjadi Kesalahan</div>
            <div class="modal-sub" id="error-body">Silakan coba lagi.</div>
            <div class="modal-ft">
                <button class="mbtn primary" onclick="closeModal('modal-error')">Oke</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const products = <?= json_encode($obatList) ?>;
        const UPLOAD_URL = '<?= $uploadUrl ?>';
        const ownerMode = <?= $isOwner ? 'true' : 'false' ?>;

        const iconStyles = ['green', 'red', 'amber', 'blue'];
        const iconEmojis = ['💊', '💉', '🧴', '🩺', '🌿', '🍃'];
        let cart = {},
            currentCat = 'Semua',
            searchQ = '';
        let activeMember = null;

        // ── Data rekening bank ──
        const bankRekening = {
            'BCA':    { label: 'Bank BCA',    norek: '0921-0000000', an: 'Apotek Healplus' },
            'BRI':    { label: 'Bank BRI',    norek: '1234-5678901', an: 'Apotek Healplus' },
            'Mandiri':{ label: 'Bank Mandiri',norek: '9876-5432100', an: 'Apotek Healplus' },
            'BNI':    { label: 'Bank BNI',    norek: '1111-2222333', an: 'Apotek Healplus' },
        };

        // ── Data akun E-Wallet ──
        const ewalletData = {
            'Dana':      { label: 'Dana',      norek: '+62-890-0000-0000', an: 'Apotek Healplus' },
            'OVO':       { label: 'OVO',       norek: '+62-890-0000-0000', an: 'Apotek Healplus' },
            'GoPay':     { label: 'GoPay',     norek: '+62-890-0000-0000', an: 'Apotek Healplus' },
            'ShopeePay': { label: 'ShopeePay', norek: '+62-890-0000-0000', an: 'Apotek Healplus' },
            'LinkAja':   { label: 'LinkAja',   norek: '+62-890-0000-0000', an: 'Apotek Healplus' },
        };

        // ── Diskon 1-10% berdasarkan subtotal ──
        function getDiscRate(sub) {
            if (sub >= 1000000) return 10;
            if (sub >= 900000)  return 9;
            if (sub >= 800000)  return 8;
            if (sub >= 700000)  return 7;
            if (sub >= 600000)  return 6;
            if (sub >= 500000)  return 5;
            if (sub >= 200000)  return 4;
            if (sub >= 100000)  return 3;
            if (sub >= 50000)   return 2;
            return 1;
        }

        // ════════════════════════════════════════
        //  PESANAN helpers
        // ════════════════════════════════════════

        /**
         * Tampilkan modal stok HABIS (stok = 0), lalu bisa redirect ke pesanan.php
         */
        function showPesananHabis(namaObat) {
            document.getElementById('ph-nama').textContent  = namaObat;
            document.getElementById('ph-nama2').textContent = namaObat;
            openModal('modal-pesan-habis');
        }

        /**
         * Tampilkan modal stok KURANG (qty > stok), lalu bisa redirect ke pesanan.php
         */
        function showPesananKurang(namaObat, stokTersedia) {
            document.getElementById('pk-nama').textContent  = namaObat;
            document.getElementById('pk-nama2').textContent = namaObat;
            document.getElementById('pk-stok').textContent  = stokTersedia;
            openModal('modal-pesan-kurang');
        }

        /**
         * Redirect ke pesanan.php (dipanggil dari kedua modal pesanan)
         */
        function goToPesanan() {
            window.location.href = 'pesanan.php';
        }

        // ── MEMBER ──
        function selectMember(id) {
            if (ownerMode) return;
            if (!id) { clearMember(); return; }
            const opt = document.querySelector(`#member-sel option[value="${id}"]`);
            if (!opt) return;
            activeMember = { id, nama: opt.dataset.nama, hp: opt.dataset.hp };
            document.getElementById('m-av').textContent   = activeMember.nama.charAt(0).toUpperCase();
            document.getElementById('m-name').textContent = activeMember.nama;
            document.getElementById('m-hp').textContent   = activeMember.hp;
            document.getElementById('member-picker').classList.add('hidden');
            document.getElementById('member-active').classList.remove('hidden');
            updateTotals();
            updatePayBtn();
        }

        function clearMember() {
            activeMember = null;
            document.getElementById('member-sel').value = '';
            document.getElementById('member-picker').classList.remove('hidden');
            document.getElementById('member-active').classList.add('hidden');
            document.getElementById('disc-row').classList.add('hidden');
            updateTotals();
            updatePayBtn();
        }

        function submitRegMember() {
            if (ownerMode) return;
            const nama   = document.getElementById('nm-nama').value.trim();
            const hp     = document.getElementById('nm-hp').value.trim();
            const alamat = document.getElementById('nm-alamat').value.trim();
            if (!nama || !hp) { showToast('Nama dan No. HP wajib diisi!', true); return; }

            const fd = new FormData();
            fd.append('ajax_tambah_member', '1');
            fd.append('nama_lengkap', nama);
            fd.append('no_hp', hp);
            fd.append('alamat', alamat);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json()).then(d => {
                    if (d.success) {
                        closeModal('modal-reg-member');
                        showToast(d.message);
                        const sel = document.getElementById('member-sel');
                        const opt = document.createElement('option');
                        opt.value = d.id;
                        opt.dataset.nama = d.nama;
                        opt.dataset.hp   = d.no_hp;
                        opt.textContent  = `${d.nama} — ${d.no_hp}`;
                        sel.appendChild(opt);
                        sel.value = d.id;
                        selectMember(d.id);
                        ['nm-nama','nm-hp','nm-alamat'].forEach(i => document.getElementById(i).value = '');
                    } else showToast(d.message, true);
                }).catch(() => showToast('Koneksi gagal.', true));
        }

        // ── PRODUCTS ──
        function renderProducts() {
            const grid = document.getElementById('product-grid');
            const list = products.filter(p => {
                const mc = currentCat === 'Semua' || (p.nama_kategori || '').toLowerCase() === currentCat.toLowerCase();
                const ms = p.nama_obat.toLowerCase().includes(searchQ.toLowerCase());
                return mc && ms;
            });

            if (!list.length) {
                grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted)">
                    <i class="fas fa-search" style="font-size:32px;color:#d0ddd0"></i>
                    <p style="margin-top:10px">Obat tidak ditemukan</p></div>`;
                return;
            }

            grid.innerHTML = list.map((p, i) => {
                const st   = iconStyles[i % iconStyles.length];
                const em   = iconEmojis[i % iconEmojis.length];
                const stok = parseInt(p.stok);

                // ── Stok HABIS (= 0) ──
                if (stok === 0) {
                    const thumb = p.gambar
                        ? `<img src="${UPLOAD_URL}${p.gambar}" alt="${p.nama_obat}" class="product-img" style="filter:grayscale(60%)">`
                        : `<div class="product-icon ${st}" style="opacity:.55">${em}</div>`;

                    return `<div class="product-card out-of-stock">
                        <span class="badge-habis"><i class="fas fa-ban" style="font-size:9px;margin-right:3px"></i>Habis</span>
                        ${thumb}
                        <div class="product-name">${p.nama_obat}</div>
                        <div class="product-price">Rp ${fmt(p.harga_jual)}</div>
                        <div class="product-stock" style="color:var(--red);font-weight:700">Stok: 0</div>
                        <button class="btn-pesan-card" onclick="showPesananHabis('${p.nama_obat.replace(/'/g,"\\'")}')">
                            <i class="fas fa-box"></i> Pesan
                        </button>
                    </div>`;
                }

                // ── Stok TERSEDIA ──
                const badge = cart[p.id_obat]
                    ? `<div style="position:absolute;top:8px;right:8px;background:var(--green);color:#fff;border-radius:20px;padding:1px 8px;font-size:11px;font-weight:700">${cart[p.id_obat].qty}</div>`
                    : '';
                const thumb = p.gambar
                    ? `<img src="${UPLOAD_URL}${p.gambar}" alt="${p.nama_obat}" class="product-img">`
                    : `<div class="product-icon ${st}">${em}</div>`;
                const clickAttr = ownerMode ? '' : `onclick="addCart(${p.id_obat})"`;

                return `<div class="product-card" ${clickAttr}>${badge}
                    ${thumb}
                    <div class="product-name">${p.nama_obat}</div>
                    <div class="product-price">Rp ${fmt(p.harga_jual)}</div>
                    <div class="product-stock">Stok: ${p.stok}</div>
                </div>`;
            }).join('');
        }

        function filterProducts(v) { searchQ = v; renderProducts(); }

        function filterCat(btn, cat) {
            document.querySelectorAll('.btn-filter').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentCat = cat;
            renderProducts();
        }

        // ── CART ──
        function addCart(id) {
            if (ownerMode) return;
            const p = products.find(x => x.id_obat == id);
            if (!p) return;

            const stok = parseInt(p.stok);

            // Stok sudah habis sebelum ditambah
            if (stok === 0) {
                showPesananHabis(p.nama_obat);
                return;
            }

            if (!cart[id]) cart[id] = { id_obat: id, nama: p.nama_obat, harga: parseFloat(p.harga_jual), qty: 0 };

            // Qty akan melebihi stok → tampilkan modal pesanan kurang
            if (cart[id].qty >= stok) {
                showPesananKurang(p.nama_obat, stok);
                return;
            }

            cart[id].qty++;
            renderCart();
            renderProducts();
            updatePayBtn();
        }

        function changeQty(id, d) {
            if (ownerMode) return;
            if (!cart[id]) return;

            const p    = products.find(x => x.id_obat == id);
            const stok = p ? parseInt(p.stok) : 0;

            // Tambah qty melebihi stok → modal pesanan kurang
            if (d > 0 && cart[id].qty >= stok) {
                showPesananKurang(cart[id].nama, stok);
                return;
            }

            cart[id].qty += d;
            if (cart[id].qty <= 0) delete cart[id];
            renderCart();
            renderProducts();
            updatePayBtn();
        }

        function renderCart() {
            const c    = document.getElementById('cart-items');
            const keys = Object.keys(cart);
            if (!keys.length) {
                c.innerHTML = `<div class="empty-cart"><i class="fas fa-cart-plus"></i>
                    <p>Keranjang kosong</p>
                    <p style="font-size:11px;color:#c0cfc0">Pilih produk di kiri</p></div>`;
                updateTotals();
                return;
            }
            c.innerHTML = keys.map(id => {
                const item = cart[id];
                const sub  = item.qty * item.harga;
                return `<div class="cart-item">
                    <div class="cart-item-info">
                        <div class="cart-item-name">${item.nama}</div>
                        <div class="cart-item-price">Rp ${fmt(item.harga)} × ${item.qty}</div>
                    </div>
                    <div class="qty-ctrl">
                        <button class="qty-btn rm" onclick="changeQty(${id},-1)"><i class="fas fa-minus" style="font-size:10px"></i></button>
                        <span class="qty-num">${item.qty}</span>
                        <button class="qty-btn"    onclick="changeQty(${id},1)"><i class="fas fa-plus"  style="font-size:10px"></i></button>
                    </div>
                    <div class="cart-item-subtotal">Rp ${fmt(sub)}</div>
                </div>`;
            }).join('');
            updateTotals();
        }

        function getSubtotal() {
            return Object.values(cart).reduce((s, i) => s + i.qty * i.harga, 0);
        }

        function getTotal() {
            const sub = getSubtotal();
            if (activeMember && sub > 0) return sub - Math.round(sub * getDiscRate(sub) / 100);
            return sub;
        }

        function updateTotals() {
            const sub = getSubtotal();
            document.getElementById('s-subtotal').textContent = 'Rp ' + fmt(sub);
            if (activeMember && sub > 0) {
                const r    = getDiscRate(sub);
                const disc = Math.round(sub * r / 100);
                document.getElementById('disc-row').classList.remove('hidden');
                document.getElementById('disc-lbl').textContent = `Diskon Member (${r}%)`;
                document.getElementById('disc-val').textContent = `- Rp ${fmt(disc)}`;
                document.getElementById('m-disc').textContent   = `Diskon ${r}%`;
                document.getElementById('s-total').textContent  = 'Rp ' + fmt(sub - disc);
            } else {
                document.getElementById('disc-row').classList.add('hidden');
                document.getElementById('s-total').textContent = 'Rp ' + fmt(sub);
                if (activeMember) document.getElementById('m-disc').textContent = 'Diskon 1%';
            }
            calcKemb();
        }

        function calcKemb() {
            const total = getTotal();
            const bayar = parseFloat(document.getElementById('cash-in').value) || 0;
            const row   = document.getElementById('kemb-row');
            if (bayar > 0 && total > 0) {
                row.classList.remove('hidden');
                const kemb = bayar - total;
                document.getElementById('kemb-val').textContent  = 'Rp ' + fmt(kemb);
                document.getElementById('kemb-val').style.color = kemb >= 0 ? 'var(--green)' : 'var(--red)';
            } else row.classList.add('hidden');
            updatePayBtn();
        }

        // ── Toggle tampilan field sesuai metode ──
        function togglePayFields(v) {
            document.getElementById('cash-sec').style.display   = v === 'cash'     ? 'flex' : 'none';
            document.getElementById('bank-sec').style.display   = v === 'transfer' ? 'flex' : 'none';
            document.getElementById('ewallet-sec').style.display= v === 'ewallet'  ? 'flex' : 'none';
            if (v !== 'transfer') document.getElementById('bank-sel').value   = '';
            if (v !== 'ewallet')  document.getElementById('ewallet-sel').value= '';
            updatePayBtn();
        }

        function updatePayBtn() {
            const total  = getTotal();
            const method = document.getElementById('pay-method').value;
            let ok = total > 0;
            if (method === 'cash')     ok = ok && (parseFloat(document.getElementById('cash-in').value) || 0) >= total;
            else if (method === 'transfer') ok = ok && document.getElementById('bank-sel').value !== '';
            else if (method === 'ewallet')  ok = ok && document.getElementById('ewallet-sel').value !== '';
            document.getElementById('btn-pay').disabled = !ok;
        }

        // ── KONFIRMASI TRANSAKSI ──
        function confirmTrx() {
            if (ownerMode) return;
            const total  = getTotal();
            const sub    = getSubtotal();
            const method = document.getElementById('pay-method').value;

            if (method === 'transfer') {
                const bankKey = document.getElementById('bank-sel').value;
                const bank    = bankRekening[bankKey];
                if (!bank) { showToast('Pilih bank terlebih dahulu!', true); return; }
                document.getElementById('mt-bank').textContent  = bank.label;
                document.getElementById('mt-norek').textContent = bank.norek;
                document.getElementById('mt-an').textContent    = bank.an;
                document.getElementById('mt-total').textContent = total > 0 ? 'Rp ' + fmt(total) : 'Rp -';
                openModal('modal-transfer');
                return;
            }

            if (method === 'ewallet') {
                const ewKey = document.getElementById('ewallet-sel').value;
                const ew    = ewalletData[ewKey];
                if (!ew) { showToast('Pilih payment terlebih dahulu!', true); return; }
                document.getElementById('ew-payment').textContent = ew.label;
                document.getElementById('ew-norek').textContent   = ew.norek;
                document.getElementById('ew-an').textContent      = ew.an;
                document.getElementById('ew-total').textContent   = total > 0 ? 'Rp ' + fmt(total) : 'Rp -';
                openModal('modal-ewallet');
                return;
            }

            const bayar = parseFloat(document.getElementById('cash-in').value);
            let body    = `Total: <strong>Rp ${fmt(total)}</strong><br>`;
            if (activeMember) {
                const r = getDiscRate(sub);
                body   += `Member: <strong>${activeMember.nama}</strong> · Diskon ${r}%<br>`;
            }
            body += `Bayar: <strong>Rp ${fmt(bayar)}</strong><br>Kembalian: <strong>Rp ${fmt(bayar - total)}</strong>`;
            document.getElementById('confirm-body').innerHTML = body;
            openModal('modal-confirm');
        }

        // ── PROSES TRANSAKSI ──
        function processTrx() {
            closeModal('modal-confirm');
            closeModal('modal-transfer');
            closeModal('modal-ewallet');

            const total  = getTotal();
            const method = document.getElementById('pay-method').value;
            const bayar  = method === 'cash'
                ? parseFloat(document.getElementById('cash-in').value)
                : total;

            const items = Object.values(cart).map(i => ({
                id_obat: i.id_obat, nama: i.nama, harga: i.harga, jumlah: i.qty
            }));

            const fd = new FormData();
            fd.append('ajax_transaksi', '1');
            fd.append('items', JSON.stringify(items));
            fd.append('total', total);
            fd.append('bayar', bayar);
            fd.append('metode', method);
            if (activeMember) fd.append('id_member', activeMember.id);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json()).then(data => {
                    if (data.success) {
                        lastTrxData = {
                            id: data.id_penjualan,
                            tanggal: data.tanggal,
                            items,
                            subtotal: getSubtotal(),
                            total:    data.total,
                            bayar:    data.bayar,
                            kembalian:data.kembalian,
                            metode:   document.getElementById('pay-method').selectedOptions[0].text,
                            member:   activeMember ? activeMember.nama : null,
                            diskon:   activeMember ? Math.round(getSubtotal() * getDiscRate(getSubtotal()) / 100) : 0,
                            discRate: activeMember ? getDiscRate(getSubtotal()) : 0,
                            kasir:    '<?= htmlspecialchars($user["nama_user"]) ?>'
                        };
                        renderStruk(lastTrxData);
                        openModal('modal-success');
                        items.forEach(it => {
                            const p = products.find(x => x.id_obat == it.id_obat);
                            if (p) p.stok -= it.jumlah;
                        });
                    } else {
                        document.getElementById('error-body').textContent = data.message;
                        openModal('modal-error');
                    }
                }).catch(() => {
                    document.getElementById('error-body').textContent = 'Koneksi gagal.';
                    openModal('modal-error');
                });
        }

        // ── TABS ──
        function switchTab(tab, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-pos').style.display     = tab === 'pos'     ? 'flex' : 'none';
            document.getElementById('tab-history').style.display = tab === 'history' ? 'flex' : 'none';
        }

        // ── MODAL helpers ──
        function openModal(id)  { document.getElementById(id).classList.add('show'); }
        function closeModal(id) { document.getElementById(id).classList.remove('show'); }

        // Tutup modal saat klik overlay
        ['modal-transfer','modal-ewallet','modal-pesan-habis','modal-pesan-kurang'].forEach(id => {
            document.getElementById(id).addEventListener('click', function(e) {
                if (e.target === this) closeModal(id);
            });
        });

        // ── TOAST ──
        function showToast(msg, error = false) {
            const t = document.getElementById('toast');
            t.innerHTML  = `<i class="fas fa-${error ? 'exclamation-circle' : 'check-circle'}"></i> ${msg}`;
            t.className  = 'toast show' + (error ? ' error' : '');
            setTimeout(() => t.className = 'toast', 2800);
        }

        // ── DROPDOWN ──
        function toggleDropdown() {
            const m = document.getElementById('ddmenu');
            m.style.display = m.style.display === 'block' ? 'none' : 'block';
        }
        document.addEventListener('click', function(e) {
            const w = document.getElementById('ddwrap');
            if (w && !w.contains(e.target)) document.getElementById('ddmenu').style.display = 'none';
        });

        // ── UTILS ──
        function fmt(n) { return Number(n).toLocaleString('id-ID'); }

        // ── STRUK ──
        let lastTrxData = null;

        function renderStruk(d) {
            const tgl    = new Date(d.tanggal);
            const tglStr = tgl.toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' });
            const jamStr = tgl.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });

            const itemRows = d.items.map(it => `
                <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0">
                    <div>
                        <div style="font-weight:600">${it.nama}</div>
                        <div style="color:var(--muted);font-size:12px">${it.jumlah} × Rp ${fmt(it.harga)}</div>
                    </div>
                    <div style="font-weight:700;white-space:nowrap;margin-left:12px">Rp ${fmt(it.jumlah * it.harga)}</div>
                </div>`).join('');

            const diskonRow = d.diskon > 0 ? `
                <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--purple);font-weight:600;padding:3px 0">
                    <span>Diskon Member (${d.discRate}%)</span>
                    <span>- Rp ${fmt(d.diskon)}</span>
                </div>` : '';

            const memberBadge = d.member ? `
                <div style="background:var(--purple-pale);border-radius:8px;padding:8px 12px;display:flex;align-items:center;gap:8px">
                    <i class="fas fa-user-tag" style="color:var(--purple)"></i>
                    <div>
                        <div style="font-size:12px;color:var(--muted)">Member</div>
                        <div style="font-size:13px;font-weight:700;color:var(--purple)">${d.member}</div>
                    </div>
                </div>` : '';

            const kembalianRow = d.kembalian > 0 ? `
                <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0">
                    <span style="color:var(--muted)">Kembalian</span>
                    <span style="font-weight:700;color:var(--green)">Rp ${fmt(d.kembalian)}</span>
                </div>` : '';

            document.getElementById('struk-body').innerHTML = `
                <div style="display:flex;justify-content:space-between;font-size:12.5px;color:var(--muted)">
                    <span><i class="fas fa-hashtag"></i> TRX-${String(d.id).padStart(4,'0')}</span>
                    <span>${tglStr}, ${jamStr}</span>
                </div>
                <div style="font-size:12.5px;color:var(--muted)">
                    <i class="fas fa-user-circle"></i> Kasir: <strong style="color:var(--text)">${d.kasir}</strong>
                </div>
                ${memberBadge}
                <div style="border-top:1.5px dashed var(--border);margin:4px 0"></div>
                <div style="display:flex;flex-direction:column;gap:4px">${itemRows}</div>
                <div style="border-top:1.5px dashed var(--border);margin:4px 0"></div>
                <div style="display:flex;flex-direction:column;gap:4px">
                    <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0">
                        <span style="color:var(--muted)">Subtotal</span><span>Rp ${fmt(d.subtotal)}</span>
                    </div>
                    ${diskonRow}
                    <div style="display:flex;justify-content:space-between;font-size:15px;font-weight:800;padding:6px 0;border-top:1.5px solid var(--border);margin-top:2px">
                        <span>TOTAL</span><span style="color:var(--green)">Rp ${fmt(d.total)}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0">
                        <span style="color:var(--muted)">${d.metode}</span><span>Rp ${fmt(d.bayar)}</span>
                    </div>
                    ${kembalianRow}
                </div>
                <div style="text-align:center;padding:8px 0 2px;font-size:12px;color:var(--muted);border-top:1px solid var(--border);margin-top:4px">
                    <i class="fas fa-heart" style="color:var(--red)"></i> Terima kasih atas kepercayaan Anda!
                </div>`;
        }

        function printStruk() {
            if (!lastTrxData) return;
            const d      = lastTrxData;
            const tgl    = new Date(d.tanggal);
            const tglStr = tgl.toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' });
            const jamStr = tgl.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });

            const itemRows = d.items.map(it => `
                <tr>
                    <td style="padding:4px 0">${it.nama}</td>
                    <td style="text-align:center;padding:4px 8px">${it.jumlah}</td>
                    <td style="text-align:right;padding:4px 0">Rp ${fmt(it.harga)}</td>
                    <td style="text-align:right;padding:4px 0;font-weight:700">Rp ${fmt(it.jumlah * it.harga)}</td>
                </tr>`).join('');

            const diskonRow    = d.diskon > 0
                ? `<tr><td colspan="3" style="padding:3px 0;color:#666">Diskon Member (${d.discRate}%)</td><td style="text-align:right;padding:3px 0;color:#666">- Rp ${fmt(d.diskon)}</td></tr>`
                : '';
            const kembalianRow = d.kembalian > 0
                ? `<tr><td colspan="3" style="padding:3px 0">Kembalian</td><td style="text-align:right;padding:3px 0;font-weight:700">Rp ${fmt(d.kembalian)}</td></tr>`
                : '';
            const memberRow    = d.member
                ? `<p style="margin:2px 0">Member: <strong>${d.member}</strong>${d.discRate > 0 ? ` (Diskon ${d.discRate}%)` : ''}</p>`
                : '';

            const html = `<!DOCTYPE html><html><head>
<meta charset="UTF-8"><title>Struk #TRX-${String(d.id).padStart(4,'0')}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Courier New',monospace;font-size:13px;color:#000;width:300px;margin:0 auto;padding:16px 8px}
h1{text-align:center;font-size:18px;letter-spacing:1px;margin-bottom:4px}
.center{text-align:center}.divider{border-top:1px dashed #000;margin:8px 0}
table{width:100%;border-collapse:collapse}.total-row td{font-weight:700;font-size:14px;border-top:1px solid #000;padding-top:4px}
p{margin:2px 0;font-size:12px}
@media print{body{margin:0;width:80mm}@page{size:80mm auto;margin:0}}
</style></head><body>
<h1>🌿 APOTEK</h1>
<p class="center" style="font-size:11px">Sistem Manajemen Apotek</p>
<div class="divider"></div>
<p>No. Struk : TRX-${String(d.id).padStart(4,'0')}</p>
<p>Tanggal   : ${tglStr}</p><p>Jam       : ${jamStr}</p>
<p>Kasir     : ${d.kasir}</p>${memberRow}
<div class="divider"></div>
<table><thead><tr>
    <th style="text-align:left">Item</th><th style="text-align:center">Qty</th>
    <th style="text-align:right">Harga</th><th style="text-align:right">Sub</th>
</tr></thead><tbody>${itemRows}</tbody></table>
<div class="divider"></div>
<table>
<tr><td colspan="3" style="padding:3px 0">Subtotal</td><td style="text-align:right;padding:3px 0">Rp ${fmt(d.subtotal)}</td></tr>
${diskonRow}
<tr class="total-row"><td colspan="3" style="padding:6px 0 3px">TOTAL</td><td style="text-align:right;padding:6px 0 3px">Rp ${fmt(d.total)}</td></tr>
<tr><td colspan="3" style="padding:3px 0">${d.metode}</td><td style="text-align:right;padding:3px 0">Rp ${fmt(d.bayar)}</td></tr>
${kembalianRow}
</table>
<div class="divider"></div>
<p class="center" style="margin-top:8px">*** Terima Kasih ***</p>
<p class="center">Semoga lekas sembuh 💊</p>
<p class="center" style="font-size:10px;margin-top:6px;color:#666">Simpan struk ini sebagai bukti pembelian</p>
</body></html>`;

            const win = window.open('', '_blank', 'width=400,height=600');
            win.document.write(html);
            win.document.close();
            win.focus();
            setTimeout(() => win.print(), 400);
        }

        // ── INIT ──
        renderProducts();
        togglePayFields('cash');
    </script>
</body>

</html>