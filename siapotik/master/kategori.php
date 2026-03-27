<?php
session_start();
require_once "../koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

$username = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user = mysqli_fetch_assoc($queryUser);

// AJAX: Tambah
if (isset($_POST['ajax_tambah'])) {
    header('Content-Type: application/json');
    ob_start();
    $nama = mysqli_real_escape_string($conn, $_POST['nama_kategori'] ?? '');
    if (empty($nama)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Nama kategori tidak boleh kosong!']);
        exit;
    }
    $ok  = mysqli_query($conn, "INSERT INTO kategori (nama_kategori) VALUES ('$nama')");
    $nid = mysqli_insert_id($conn);
    ob_end_clean();
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Kategori berhasil ditambahkan!', 'id' => $nid, 'nama' => htmlspecialchars($nama)]
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Edit
if (isset($_POST['ajax_edit'])) {
    header('Content-Type: application/json');
    ob_start();
    $id   = (int) ($_POST['id_kategori'] ?? 0);
    $nama = mysqli_real_escape_string($conn, $_POST['nama_kategori'] ?? '');
    if (empty($nama)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Nama tidak boleh kosong!']);
        exit;
    }
    $ok = mysqli_query($conn, "UPDATE kategori SET nama_kategori='$nama' WHERE id_kategori='$id'");
    ob_end_clean();
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Kategori berhasil diperbarui!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Hapus
if (isset($_POST['ajax_hapus'])) {
    header('Content-Type: application/json');
    ob_start();
    $id  = (int) ($_POST['id_kategori'] ?? 0);
    $cek = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM obat WHERE id_kategori='$id'"));
    if ($cek['c'] > 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Kategori masih digunakan oleh ' . $cek['c'] . ' obat!']);
        exit;
    }
    $ok  = mysqli_query($conn, "DELETE FROM kategori WHERE id_kategori='$id'");
    ob_end_clean();
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Kategori berhasil dihapus!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// Fetch dengan jumlah produk
$query = mysqli_query(
    $conn,
    "SELECT k.*, COUNT(o.id_obat) as jumlah_produk
     FROM kategori k LEFT JOIN obat o ON k.id_kategori=o.id_kategori
     GROUP BY k.id_kategori ORDER BY k.id_kategori ASC"
);
$kategoris = [];
while ($r = mysqli_fetch_assoc($query)) $kategoris[] = $r;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Kategori Obat — Apotek</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        :root {
            --green: #2d6a4f;
            --green-mid: #40916c;
            --green-light: #52b788;
            --green-pale: #d8f3dc;
            --green-btn: #1b4332;
            --bg: #f4f6f3;
            --surface: #fff;
            --border: #e0e6de;
            --text: #1a2e1a;
            --muted: #6b7e6b;
            --red: #e63946;
            --red-pale: #ffeef0;
            --blue: #1d6fa4;
            --blue-pale: #ddeeff;
            --amber: #e07b00;
            --amber-pale: #fff3e0;
            --purple: #7c3aed;
            --purple-pale: #f3eeff;
            --teal: #0d9488;
            --teal-pale: #ccfbf1;
            --pink: #db2777;
            --pink-pale: #fce7f3;
            --radius: 16px;
            --shadow: 0 2px 12px rgba(0, 0, 0, .07);
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column
        }

        .topnav {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            height: 56px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 6px rgba(0, 0, 0, .05)
        }

        .brand {
            font-weight: 700;
            font-size: 17px;
            color: var(--green);
            letter-spacing: -.3px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .brand i {
            color: var(--green-light)
        }

        .page-title {
            font-size: 14px;
            font-weight: 600;
            margin-left: 8px
        }

        .topnav-right {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 10px
        }

        .icon-btn {
            width: 36px;
            height: 36px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--muted);
            font-size: 15px;
            position: relative;
            transition: border-color .2s, color .2s
        }

        .icon-btn:hover {
            border-color: var(--green-light);
            color: var(--green)
        }

        .badge-dot {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 7px;
            height: 7px;
            background: var(--red);
            border-radius: 50%
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 12px 5px 5px;
            border: 1px solid var(--border);
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            color: var(--text);
            background: var(--surface);
            transition: border-color .2s
        }

        .user-chip:hover {
            border-color: var(--green-light)
        }

        .avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--green-pale);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700
        }

        .ddwrap {
            position: relative
        }

        .ddmenu {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .1);
            min-width: 180px;
            padding: 8px;
            display: none;
            z-index: 200
        }

        .ddwrap:hover .ddmenu {
            display: block
        }

        .ddmenu a,
        .ddmenu span {
            display: block;
            padding: 8px 12px;
            font-size: 13px;
            color: var(--text);
            border-radius: 8px;
            text-decoration: none
        }

        .ddmenu a:hover {
            background: var(--green-pale);
            color: var(--green)
        }

        .ddmenu .role-lbl {
            color: var(--muted);
            font-size: 12px
        }

        .ddmenu hr {
            border: none;
            border-top: 1px solid var(--border);
            margin: 4px 0
        }

        .ddmenu .logout {
            color: var(--red) !important
        }

        .ddmenu .logout:hover {
            background: var(--red-pale) !important
        }

        .app-body {
            display: flex;
            flex: 1;
            overflow: hidden;
            height: calc(100vh - 56px)
        }

        .sidebar {
            width: 220px;
            min-width: 220px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            overflow-y: auto;
            padding: 16px 0;
            display: flex;
            flex-direction: column
        }

        .sb-sec {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--muted);
            padding: 8px 20px 4px
        }

        .sb-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 20px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--muted);
            text-decoration: none;
            transition: background .15s, color .15s
        }

        .sb-link i {
            width: 16px;
            text-align: center;
            font-size: 13px
        }

        .sb-link:hover {
            background: var(--green-pale);
            color: var(--green)
        }

        .sb-link.active {
            background: var(--green-pale);
            color: var(--green);
            font-weight: 700;
            border-right: 3px solid var(--green)
        }

        .sb-footer {
            margin-top: auto;
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            font-size: 12px;
            color: var(--muted)
        }

        .sb-footer strong {
            display: block;
            color: var(--text);
            font-size: 13px
        }

        .main-content {
            flex: 1;
            overflow-y: auto;
            padding: 28px;
            display: flex;
            flex-direction: column;
            gap: 24px
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px
        }

        .page-header h2 {
            font-size: 22px;
            font-weight: 700
        }

        .page-header p {
            font-size: 13.5px;
            color: var(--muted);
            margin-top: 3px
        }

        .btn-add {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 12px;
            border: none;
            background: var(--green);
            color: #fff;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: background .2s, transform .1s
        }

        .btn-add:hover {
            background: var(--green-btn)
        }

        .btn-add:active {
            transform: scale(.97)
        }

        /* GRID */
        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 18px
        }

        .category-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 30px 20px 22px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color .2s, transform .15s, box-shadow .2s;
            position: relative
        }

        .category-card:hover {
            border-color: var(--green-light);
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(45, 106, 79, .13)
        }

        .category-card.add-card {
            border: 2px dashed var(--border);
            background: transparent
        }

        .category-card.add-card:hover {
            border-color: var(--green-light);
            background: #f0faf4
        }

        .category-card.add-card .cat-name {
            color: var(--muted)
        }

        .category-card.add-card .cat-count {
            color: #aab8aa
        }

        .cat-icon-wrap {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            transition: transform .2s
        }

        .category-card:hover .cat-icon-wrap {
            transform: scale(1.07)
        }

        .cat-icon-wrap.blue {
            background: var(--blue-pale);
            color: var(--blue)
        }

        .cat-icon-wrap.red {
            background: var(--red-pale);
            color: var(--red)
        }

        .cat-icon-wrap.green {
            background: var(--green-pale);
            color: var(--green)
        }

        .cat-icon-wrap.amber {
            background: var(--amber-pale);
            color: var(--amber)
        }

        .cat-icon-wrap.purple {
            background: var(--purple-pale);
            color: var(--purple)
        }

        .cat-icon-wrap.teal {
            background: var(--teal-pale);
            color: var(--teal)
        }

        .cat-icon-wrap.pink {
            background: var(--pink-pale);
            color: var(--pink)
        }

        .cat-icon-wrap.gray {
            background: #f0f0f0;
            color: #9ca3af
        }

        .cat-name {
            font-size: 16px;
            font-weight: 700;
            text-align: center
        }

        .cat-count {
            font-size: 13px;
            color: var(--muted)
        }

        .card-actions {
            position: absolute;
            top: 10px;
            right: 10px;
            display: flex;
            gap: 5px;
            opacity: 0;
            transition: opacity .2s
        }

        .category-card:hover .card-actions {
            opacity: 1
        }

        .cact {
            width: 26px;
            height: 26px;
            border-radius: 7px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            cursor: pointer;
            color: var(--muted);
            transition: all .15s
        }

        .cact.edit:hover {
            background: var(--blue-pale);
            border-color: var(--blue);
            color: var(--blue)
        }

        .cact.del:hover {
            background: var(--red-pale);
            border-color: var(--red);
            color: var(--red)
        }

        /* MODAL */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .4);
            z-index: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s
        }

        .modal-overlay.show {
            opacity: 1;
            pointer-events: all
        }

        .modal-box {
            background: var(--surface);
            border-radius: 20px;
            padding: 28px;
            width: 400px;
            max-width: calc(100vw - 32px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, .18);
            transform: scale(.92) translateY(10px);
            transition: transform .25s;
            text-align: center
        }

        .modal-overlay.show .modal-box {
            transform: scale(1) translateY(0)
        }

        .m-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin: 0 auto 14px
        }

        .m-icon.green {
            background: var(--green-pale);
            color: var(--green)
        }

        .m-icon.blue {
            background: var(--blue-pale);
            color: var(--blue)
        }

        .m-icon.red {
            background: var(--red-pale);
            color: var(--red)
        }

        .m-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px
        }

        .m-sub {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 20px
        }

        .fg {
            display: flex;
            flex-direction: column;
            gap: 5px;
            text-align: left;
            margin-bottom: 16px
        }

        .fg label {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--muted)
        }

        .fi {
            padding: 10px 13px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            font-family: inherit;
            font-size: 14px;
            color: var(--text);
            background: var(--bg);
            outline: none;
            transition: border-color .2s, background .2s;
            width: 100%
        }

        .fi:focus {
            border-color: var(--green-light);
            background: #fff
        }

        .mfooter {
            display: flex;
            gap: 10px
        }

        .mbtn {
            flex: 1;
            padding: 11px;
            border-radius: 10px;
            border: none;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s
        }

        .mbtn.primary {
            background: var(--green);
            color: #fff
        }

        .mbtn.primary:hover {
            background: var(--green-btn)
        }

        .mbtn.secondary {
            background: var(--bg);
            color: var(--text);
            border: 1.5px solid var(--border)
        }

        .mbtn.secondary:hover {
            background: var(--border)
        }

        .mbtn.danger {
            background: var(--red);
            color: #fff
        }

        .mbtn.danger:hover {
            background: #c1121f
        }

        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--green-btn);
            color: #fff;
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .2);
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 600;
            transform: translateY(80px);
            opacity: 0;
            transition: transform .3s, opacity .3s
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1
        }

        .toast.error {
            background: var(--red)
        }

        ::-webkit-scrollbar {
            width: 5px
        }

        ::-webkit-scrollbar-track {
            background: transparent
        }

        ::-webkit-scrollbar-thumb {
            background: #c8d8c8;
            border-radius: 4px
        }
    </style>
</head>

<body>

    <nav class="topnav">
        <a href="#" class="brand"><i class="fas fa-capsules"></i> APOTEK</a>
        <span class="page-title">/ Kategori Obat</span>
        <div class="topnav-right">
            <div class="icon-btn"><i class="fas fa-bell"></i><span class="badge-dot"></span></div>
            <div class="icon-btn"><i class="fas fa-cog"></i></div>
            <div class="ddwrap">
                <div class="user-chip">
                    <div class="avatar"><?= strtoupper(substr($user['nama_user'], 0, 2)) ?></div>
                    <?= htmlspecialchars($user['nama_user']) ?>
                    <i class="fas fa-chevron-down" style="color:var(--muted);font-size:11px"></i>
                </div>
                <div class="ddmenu">
                    <span class="role-lbl">Role: <?= htmlspecialchars($user['role']) ?></span>
                    <hr>
                    <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="app-body">
        <aside class="sidebar">
            <div class="sb-sec">Core</div>
            <a class="sb-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <div class="sb-sec">Master Data</div>
            <a class="sb-link active" href="kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <a class="sb-link" href="supplier.php"><i class="fas fa-truck"></i> Supplier</a>
            <a class="sb-link" href="obat.php"><i class="fas fa-pills"></i> Obat</a>
            <div class="sb-sec">Transaksi</div>
            <a class="sb-link" href="../transaksi/pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
            <a class="sb-link" href="../transaksi/penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
            <div class="sb-sec">Laporan</div>
            <a class="sb-link" href="../laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
            <a class="sb-link" href="../laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
            <a class="sb-link" href="../laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
            <div class="sb-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <div class="main-content">
            <div class="page-header">
                <div>
                    <h2>Kategori Obat</h2>
                    <p>Kelola kategori produk apotek</p>
                </div>
                <button class="btn-add" onclick="openTambah()">
                    <i class="fas fa-plus"></i> Tambah Kategori
                </button>
            </div>

            <div class="category-grid" id="cat-grid">
                <?php
                $iconMap = [
                    'obat bebas'         => ['fa-pills',                   'blue'],
                    'obat keras'         => ['fa-prescription-bottle-alt', 'red'],
                    'herbal'             => ['fa-leaf',                    'green'],
                    'vitamin'            => ['fa-capsules',                'amber'],
                    'vitamin & suplemen' => ['fa-capsules',                'amber'],
                    'suplemen'           => ['fa-dumbbell',                'teal'],
                    'produk bayi'        => ['fa-baby',                    'purple'],
                    'antibiotik'         => ['fa-bacteria',                'teal'],
                    'default'            => ['fa-pills',                   'blue'],
                ];
                $colorCycle = ['blue', 'red', 'green', 'amber', 'purple', 'teal', 'pink'];
                $iconCycle  = ['fa-pills', 'fa-prescription-bottle-alt', 'fa-leaf', 'fa-capsules', 'fa-baby', 'fa-bacteria', 'fa-dumbbell'];
                $ci = 0;

                foreach ($kategoris as $k):
                    $key   = strtolower($k['nama_kategori']);
                    $m     = $iconMap[$key] ?? null;
                    $icon  = $m ? $m[0] : $iconCycle[$ci % count($iconCycle)];
                    $color = $m ? $m[1] : $colorCycle[$ci % count($colorCycle)];
                    $ci++;
                ?>
                    <div class="category-card" id="card-<?= $k['id_kategori'] ?>">
                        <div class="card-actions">
                            <button class="cact edit" title="Edit"
                                onclick="openEdit(<?= $k['id_kategori'] ?>,'<?= addslashes(htmlspecialchars($k['nama_kategori'])) ?>',event)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="cact del" title="Hapus"
                                onclick="confirmHapus(<?= $k['id_kategori'] ?>,'<?= addslashes(htmlspecialchars($k['nama_kategori'])) ?>',event)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        <div class="cat-icon-wrap <?= $color ?>"><i class="fas <?= $icon ?>"></i></div>
                        <div class="cat-name"><?= htmlspecialchars($k['nama_kategori']) ?></div>
                        <div class="cat-count"><?= $k['jumlah_produk'] ?> Produk</div>
                    </div>
                <?php endforeach; ?>

                <!-- Card Tambah Baru -->
                <div class="category-card add-card" onclick="openTambah()">
                    <div class="cat-icon-wrap gray"><i class="fas fa-plus"></i></div>
                    <div class="cat-name">Tambah Baru</div>
                    <div class="cat-count">Buat kategori baru</div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL TAMBAH -->
    <div class="modal-overlay" id="m-tambah">
        <div class="modal-box">
            <div class="m-icon green"><i class="fas fa-tags"></i></div>
            <div class="m-title">Tambah Kategori</div>
            <div class="m-sub">Buat kategori baru untuk produk apotek</div>
            <div class="fg">
                <label>Nama Kategori <span style="color:var(--red)">*</span></label>
                <input type="text" id="t-nama" class="fi" placeholder="Cth: Obat Bebas, Vitamin..."
                    onkeydown="if(event.key==='Enter')submitTambah()">
            </div>
            <div class="mfooter">
                <button class="mbtn secondary" onclick="closeModal('m-tambah')">Batal</button>
                <button class="mbtn primary" onclick="submitTambah()"><i class="fas fa-save"></i> Simpan</button>
            </div>
        </div>
    </div>

    <!-- MODAL EDIT -->
    <div class="modal-overlay" id="m-edit">
        <div class="modal-box">
            <div class="m-icon blue"><i class="fas fa-edit"></i></div>
            <div class="m-title">Edit Kategori</div>
            <div class="m-sub">Ubah nama kategori</div>
            <input type="hidden" id="e-id">
            <div class="fg">
                <label>Nama Kategori <span style="color:var(--red)">*</span></label>
                <input type="text" id="e-nama" class="fi"
                    onkeydown="if(event.key==='Enter')submitEdit()">
            </div>
            <div class="mfooter">
                <button class="mbtn secondary" onclick="closeModal('m-edit')">Batal</button>
                <button class="mbtn primary" onclick="submitEdit()"><i class="fas fa-save"></i> Simpan</button>
            </div>
        </div>
    </div>

    <!-- MODAL HAPUS -->
    <div class="modal-overlay" id="m-hapus">
        <div class="modal-box">
            <div class="m-icon red"><i class="fas fa-trash"></i></div>
            <div class="m-title">Hapus Kategori?</div>
            <div class="m-sub" id="hapus-text">Kategori ini akan dihapus permanen.</div>
            <div class="mfooter">
                <button class="mbtn secondary" onclick="closeModal('m-hapus')">Batal</button>
                <button class="mbtn danger" onclick="submitHapus()"><i class="fas fa-trash"></i> Ya, Hapus</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const BASE_URL = '<?= basename($_SERVER["PHP_SELF"]) ?>';
        const colorCycle = ['blue', 'red', 'green', 'amber', 'purple', 'teal', 'pink'];
        const iconCycle = ['fa-pills', 'fa-prescription-bottle-alt', 'fa-leaf', 'fa-capsules', 'fa-baby', 'fa-bacteria', 'fa-dumbbell'];
        let cardCount = <?= count($kategoris) ?>;

        function openTambah() {
            document.getElementById('t-nama').value = '';
            openModal('m-tambah');
            setTimeout(() => document.getElementById('t-nama').focus(), 150);
        }

        function submitTambah() {
            const nama = document.getElementById('t-nama').value.trim();
            if (!nama) {
                showToast('Nama kategori tidak boleh kosong!', true);
                return;
            }
            const fd = new FormData();
            fd.append('ajax_tambah', '1');
            fd.append('nama_kategori', nama);
            fetch(BASE_URL, {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    closeModal('m-tambah');
                    showToast(d.message);
                    insertCard(d.id, d.nama);
                } else showToast(d.message, true);
            }).catch(() => showToast('Koneksi gagal.', true));
        }

        function insertCard(id, nama) {
            const color = colorCycle[cardCount % colorCycle.length];
            const icon = iconCycle[cardCount % iconCycle.length];
            cardCount++;
            const div = document.createElement('div');
            div.className = 'category-card';
            div.id = 'card-' + id;
            div.style.cssText = 'opacity:0;transform:scale(.88);transition:opacity .3s,transform .3s';
            div.innerHTML = `
        <div class="card-actions">
            <button class="cact edit" onclick="openEdit(${id},'${nama.replace(/'/g,"\\'")}',event)"><i class="fas fa-edit"></i></button>
            <button class="cact del"  onclick="confirmHapus(${id},'${nama.replace(/'/g,"\\'")}',event)"><i class="fas fa-trash"></i></button>
        </div>
        <div class="cat-icon-wrap ${color}"><i class="fas ${icon}"></i></div>
        <div class="cat-name">${nama}</div>
        <div class="cat-count">0 Produk</div>`;
            document.querySelector('.add-card').before(div);
            requestAnimationFrame(() => {
                div.style.opacity = '1';
                div.style.transform = 'scale(1)';
            });
        }

        let editId = null;

        function openEdit(id, nama, e) {
            e.stopPropagation();
            editId = id;
            document.getElementById('e-id').value = id;
            document.getElementById('e-nama').value = nama;
            openModal('m-edit');
            setTimeout(() => document.getElementById('e-nama').focus(), 150);
        }

        function submitEdit() {
            const nama = document.getElementById('e-nama').value.trim();
            if (!nama) {
                showToast('Nama tidak boleh kosong!', true);
                return;
            }
            const fd = new FormData();
            fd.append('ajax_edit', '1');
            fd.append('id_kategori', editId);
            fd.append('nama_kategori', nama);
            fetch(BASE_URL, {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    closeModal('m-edit');
                    showToast(d.message);
                    const card = document.getElementById('card-' + editId);
                    if (card) card.querySelector('.cat-name').textContent = nama;
                } else showToast(d.message, true);
            }).catch(() => showToast('Koneksi gagal.', true));
        }

        let hapusId = null;

        function confirmHapus(id, nama, e) {
            e.stopPropagation();
            hapusId = id;
            document.getElementById('hapus-text').textContent = `"${nama}" akan dihapus permanen.`;
            openModal('m-hapus');
        }

        function submitHapus() {
            const fd = new FormData();
            fd.append('ajax_hapus', '1');
            fd.append('id_kategori', hapusId);
            fetch(BASE_URL, {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(d => {
                closeModal('m-hapus');
                if (d.success) {
                    showToast(d.message);
                    const card = document.getElementById('card-' + hapusId);
                    if (card) {
                        card.style.opacity = '0';
                        card.style.transform = 'scale(.88)';
                        card.style.transition = 'opacity .3s,transform .3s';
                        setTimeout(() => card.remove(), 300);
                    }
                } else showToast(d.message, true);
            }).catch(() => showToast('Koneksi gagal.', true));
        }

        function openModal(id) {
            document.getElementById(id).classList.add('show');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }
        document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => {
            if (e.target === o) o.classList.remove('show');
        }));

        function showToast(msg, error = false) {
            const t = document.getElementById('toast');
            t.innerHTML = `<i class="fas fa-${error?'exclamation-circle':'check-circle'}"></i> ${msg}`;
            t.className = 'toast show' + (error ? ' error' : '');
            setTimeout(() => t.className = 'toast', 2800);
        }
    </script>
</body>

</html>