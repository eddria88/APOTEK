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

$username = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user = mysqli_fetch_assoc($queryUser);

$harga_jual   = (float) ($_POST['harga_jual'] ?? 0);
$stok         = (int)   ($_POST['stok'] ?? 0);
$stok_minimum = (int)   ($_POST['stok_minimum'] ?? 10);

// VALIDASI
if ($harga_jual < 0 || $stok < 0 || $stok_minimum < 0) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Harga dan stok tidak boleh minus!'
    ]);
    exit;
}

// Pastikan folder upload tersedia
$uploadDir = __DIR__ . '/../uploads/obat/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
$uploadUrl = '../uploads/obat/';

// Helper upload gambar
function handleUploadGambar($fileKey, $uploadDir)
{
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return null;
    $file    = $_FILES[$fileKey];
    $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($file['type'], $allowed)) return ['error' => 'Format tidak didukung (JPG/PNG/WEBP).'];
    if ($file['size'] > 2 * 1024 * 1024)   return ['error' => 'Ukuran gambar maks 2MB.'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'obat_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) return ['error' => 'Gagal menyimpan gambar.'];
    return $filename;
}

// AJAX: Tambah
if (isset($_POST['ajax_tambah'])) {
    header('Content-Type: application/json');
    ob_start(); // buffer output agar PHP warning tidak merusak JSON

    $nama         = mysqli_real_escape_string($conn, $_POST['nama_obat'] ?? '');
    $id_kategori  = (int)   ($_POST['id_kategori'] ?? 0);
    $satuan       = mysqli_real_escape_string($conn, $_POST['satuan'] ?? '');
    $harga_jual   = (float) ($_POST['harga_jual'] ?? 0);
    $stok         = (int)   ($_POST['stok'] ?? 0);
    $stok_minimum = (int)   ($_POST['stok_minimum'] ?? 10);

    if ($harga_jual < 0 || $stok < 0 || $stok_minimum < 0) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Harga dan stok tidak boleh minus!'
        ]);
        exit;
    }

    // Cek duplikat
    $cek = mysqli_query($conn, "SELECT * FROM obat WHERE nama_obat='$nama'");
    if (mysqli_num_rows($cek) > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Obat sudah ada!'
        ]);
        exit;
    }

    // Auto-create kolom gambar jika belum ada
    $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM obat LIKE 'gambar'");
    if (mysqli_num_rows($colCheck) === 0) {
        mysqli_query($conn, "ALTER TABLE obat ADD COLUMN gambar VARCHAR(255) DEFAULT ''");
    }

    $gambar = '';
    $up = handleUploadGambar('gambar', $uploadDir);
    if (is_array($up)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $up['error']]);
        exit;
    }
    if ($up) $gambar = $up;

    $ok = mysqli_query(
        $conn,
        "INSERT INTO obat (nama_obat,id_kategori,satuan,harga_jual,stok,stok_minimum,gambar)
         VALUES ('$nama','$id_kategori','$satuan','$harga_jual','$stok','$stok_minimum','$gambar')"
    );

    ob_end_clean();
    echo json_encode($ok
        ? ['success' => true,  'message' => 'Obat berhasil ditambahkan!']
        : ['success' => false, 'message' => 'Gagal menyimpan: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Edit
if (isset($_POST['ajax_edit'])) {
    header('Content-Type: application/json');
    ob_start();
    $id           = (int)   ($_POST['id_obat'] ?? 0);
    $nama         = mysqli_real_escape_string($conn, $_POST['nama_obat'] ?? '');
    $id_kategori  = (int)   ($_POST['id_kategori'] ?? 0);
    $satuan       = mysqli_real_escape_string($conn, $_POST['satuan'] ?? '');
    $harga_jual   = (float) ($_POST['harga_jual'] ?? 0);
    $stok         = (int)   ($_POST['stok'] ?? 0);
    $stok_minimum = (int)   ($_POST['stok_minimum'] ?? 10);

    $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT gambar FROM obat WHERE id_obat='$id'"));
    $gambar   = $existing['gambar'] ?? '';

    // CEK NAMA OBAT SUDAH ADA ATAU BELUM
    $cek = mysqli_query($conn, "SELECT * FROM obat 
WHERE nama_obat='$nama' 
AND id_obat != '$id'");

    if (mysqli_num_rows($cek) > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Nama obat sudah digunakan!'
        ]);
        exit;
    }

    // Jika user menghapus foto tanpa upload baru
    if (!empty($_POST['hapus_gambar'])) {
        if ($gambar && file_exists($uploadDir . $gambar)) unlink($uploadDir . $gambar);
        $gambar = '';
    }

    $up = handleUploadGambar('gambar', $uploadDir);
    if (is_array($up)) {
        echo json_encode(['success' => false, 'message' => $up['error']]);
        exit;
    }
    if ($up) {
        if ($gambar && file_exists($uploadDir . $gambar)) unlink($uploadDir . $gambar);
        $gambar = $up;
    }

    $ok = mysqli_query(
        $conn,
        "UPDATE obat SET nama_obat='$nama',id_kategori='$id_kategori',satuan='$satuan',
         harga_jual='$harga_jual',stok='$stok',stok_minimum='$stok_minimum',gambar='$gambar'
         WHERE id_obat='$id'"
    );

    ob_end_clean();
    echo json_encode($ok
        ? ['success' => true,  'message' => 'Data obat berhasil diperbarui!']
        : ['success' => false, 'message' => 'Gagal menyimpan: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Hapus Gambar Saja (tanpa hapus data obat)
if (isset($_POST['ajax_hapus_gambar'])) {
    header('Content-Type: application/json');
    $id  = (int)($_POST['id_obat'] ?? 0);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT gambar FROM obat WHERE id_obat='$id'"));
    if ($row && !empty($row['gambar'])) {
        $filePath = $uploadDir . $row['gambar'];
        if (file_exists($filePath)) unlink($filePath);
        mysqli_query($conn, "UPDATE obat SET gambar='' WHERE id_obat='$id'");
        echo json_encode(['success' => true, 'message' => 'Foto berhasil dihapus!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Tidak ada foto untuk dihapus.']);
    }
    exit;
}

// AJAX: Hapus
if (isset($_POST['ajax_hapus'])) {
    header('Content-Type: application/json');
    ob_start();
    $id  = (int) ($_POST['id_obat'] ?? 0);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT gambar FROM obat WHERE id_obat='$id'"));
    $ok  = mysqli_query($conn, "DELETE FROM obat WHERE id_obat='$id'");
    if ($ok && !empty($row['gambar']) && file_exists($uploadDir . $row['gambar'])) unlink($uploadDir . $row['gambar']);
    ob_end_clean();
    echo json_encode($ok
        ? ['success' => true,  'message' => 'Obat berhasil dihapus!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Get single (for edit)
if (isset($_GET['get_obat'])) {
    header('Content-Type: application/json');
    ob_start();
    $id  = (int) ($_GET['get_obat'] ?? 0);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM obat WHERE id_obat='$id'"));
    ob_end_clean();
    echo json_encode($row ?: ['error' => 'Not found']);
    exit;
}

// Fetch kategoris
$kategoriResult = mysqli_query($conn, "SELECT * FROM kategori ORDER BY nama_kategori");
$kategoris = [];
while ($k = mysqli_fetch_assoc($kategoriResult)) $kategoris[] = $k;

// Pagination + filter
$perPage   = 10;
$page      = max(1, (int)($_GET['page'] ?? 1));
$search    = mysqli_real_escape_string($conn, $_GET['search'] ?? '');
$filterKat = (int)($_GET['kategori'] ?? 0);

$where = "WHERE 1=1";
if ($search)    $where .= " AND (o.nama_obat LIKE '%$search%' OR o.id_obat LIKE '%$search%')";
if ($filterKat) $where .= " AND o.id_kategori='$filterKat'";

$totalRow  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM obat o $where"))['c'];
$totalPage = max(1, ceil($totalRow / $perPage));
$offset    = ($page - 1) * $perPage;

$dataResult = mysqli_query(
    $conn,
    "SELECT o.*, k.nama_kategori FROM obat o
     LEFT JOIN kategori k ON o.id_kategori=k.id_kategori
     $where ORDER BY o.nama_obat ASC LIMIT $perPage OFFSET $offset"
);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Obat — Apotek</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/obat.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <!-- Navigation -->
    <nav class="topnav">
        <a href="../dashboard.php" class="sb-brand">
            <img src="../uploads/logo.png" alt="Logo Apotek" style="height: 125px;" class="logo">
        </a>
        <div class="breadcrumb">
            <i class="fas fa-chevron-right"></i>
            <span class="current">Data Obat</span>
        </div>
        <div class="topnav-right">
            <div class="user-info" class="ddwrap" id="ddwrap" onclick="toggleDropdown()">
                <div class="user-texts">
                    <div class="uname"><?= htmlspecialchars($user['nama_user']) ?></div>
                    <div class="urole"><?= htmlspecialchars($user['role']) ?></div>
                </div>
                <div>
                    <div class="user-avatar"><?= strtoupper(substr($user['nama_user'], 0, 1)) ?>
                    </div>
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
            <a class="sb-link active" href="obat.php"><i class="fas fa-pills"></i> Obat</a>
            <a class="sb-link" href="member.php"><i class="fas fa-user-friends"></i> Member</a>
            <?php if ($user['role'] == 'owner'): ?>
            <div class="sb-sec">Transaksi</div>
            <a class="sb-link" href="../transaksi/pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
            <a class="sb-link" href="../transaksi/penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
            <div class="sb-sec">Laporan</div>
            <a class="sb-link" href="../laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
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
            <div class="page-header">
                <div>
                    <h2>Data Obat</h2>
                    <p>Kelola data obat dan produk apotek</p>
                </div>
                <button class="btn-add" onclick="openModal('m-tambah')">
                    <i class="fas fa-plus"></i> Tambah Obat
                </button>
            </div>

            <div class="table-card">
                <form method="GET" id="ff">
                    <div class="table-toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" placeholder="Cari nama obat atau ID..."
                                value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                onchange="document.getElementById('ff').submit()">
                        </div>
                        <div class="toolbar-right">
                            <select name="kategori" class="select-sm" onchange="document.getElementById('ff').submit()">
                                <option value="">Semua Kategori</option>
                                <?php foreach ($kategoris as $k): ?>
                                    <option value="<?= $k['id_kategori'] ?>" <?= ($filterKat == $k['id_kategori']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($k['nama_kategori']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn-export" onclick="exportCSV()">
                                <i class="fas fa-download"></i> Export
                            </button>
                        </div>
                    </div>
                </form>

                <div style="overflow-x:auto">
                    <table class="dtable" id="main-table">
                        <thead>
                            <tr>
                                <th>ID Obat</th>
                                <th>Nama Obat</th>
                                <th>Kategori</th>
                                <th>Harga Jual</th>
                                <th>Stok</th>
                                <th class="center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $bgColors = ['#d8f3dc', '#fde8ea', '#fff3e0', '#e8f4fd', '#f3eeff'];
                            $emojis   = ['💊', '💉', '🌿', '🍃', '⚕️', '🧴'];
                            $badgeMap = [
                                'obat bebas' => 'badge-bebas',
                                'obat keras' => 'badge-keras',
                                'vitamin' => 'badge-vitamin',
                                'herbal' => 'badge-herbal',
                                'default' => 'badge-other'
                            ];
                            $ci = 0;

                            if (mysqli_num_rows($dataResult) === 0):
                            ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state"><i class="fas fa-pills"></i>
                                            <p>Tidak ada data obat ditemukan</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: while ($row = mysqli_fetch_assoc($dataResult)):
                                    $katLower = strtolower($row['nama_kategori'] ?? '');
                                    $badgeCls = $badgeMap[$katLower] ?? 'badge-other';
                                    $bgColor  = $bgColors[$ci % count($bgColors)];
                                    $emoji    = $emojis[$ci % count($emojis)];
                                    $ci++;

                                    $stok    = (int)$row['stok'];
                                    $stokMin = (int)($row['stok_minimum'] ?? 10);
                                    $stokCls = $stok <= $stokMin ? 'stok-low' : ($stok <= $stokMin * 2 ? 'stok-medium' : 'stok-aman');

                                    $idDisplay = 'OBT-' . str_pad($row['id_obat'], 3, '0', STR_PAD_LEFT);
                                    $hasImg    = !empty($row['gambar']) && file_exists($uploadDir . $row['gambar']);
                                ?>
                                    <tr id="tr-<?= $row['id_obat'] ?>">
                                        <td><span class="id-mono"><?= $idDisplay ?></span></td>
                                        <td>
                                            <div class="product-info">
                                                <?php if ($hasImg): ?>
                                                    <img src="<?= $uploadUrl . htmlspecialchars($row['gambar']) ?>"
                                                        alt="" class="product-thumb">
                                                <?php else: ?>
                                                    <div class="product-thumb-placeholder" style="background:<?= $bgColor ?>">
                                                        <?= $emoji ?>
                                                    </div>
                                                <?php endif; ?>
                                                <span style="font-weight:600"><?= htmlspecialchars($row['nama_obat']) ?></span>
                                            </div>
                                        </td>
                                        <td><span class="badge-kat <?= $badgeCls ?>"><?= htmlspecialchars($row['nama_kategori'] ?? '—') ?></span></td>
                                        <td class="price-sell">Rp <?= number_format($row['harga_jual'], 0, ',', '.') ?></td>
                                        <td><span class="stok-badge <?= $stokCls ?>"><?= $stok ?></span></td>
                                        <td>
                                            <div class="action-cell">
                                                <button class="btn-icon blue" title="Edit" onclick="openEdit(<?= $row['id_obat'] ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn-icon red" title="Hapus" onclick="confirmHapus(<?= $row['id_obat'] ?>, '<?= addslashes($row['nama_obat']) ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                            <?php endwhile;
                            endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <p>Menampilkan <?= $totalRow ? (($page - 1) * $perPage + 1) : 0 ?>–<?= min($page * $perPage, $totalRow) ?> dari <?= $totalRow ?> data</p>
                    <div class="pagination">
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

    <!-- MODAL TAMBAH -->
    <div class="modal-overlay" id="m-tambah">
        <div class="modal-box">
            <div class="modal-head">
                <h3><i class="fas fa-plus-circle"></i> Tambah Obat Baru</h3>
                <button class="btn-close-modal" onclick="closeModal('m-tambah')"><i class="fas fa-times"></i></button>
            </div>
            <div class="fg2">
                <div class="fg full">
                    <label>Foto Produk <span style="color:var(--muted);font-weight:400">(opsional · JPG/PNG/WEBP · maks 2MB)</span></label>
                    <div class="img-upload-area" id="t-area">
                        <input type="file" id="t-gambar" accept="image/*" onchange="prevImg('t')">
                        <button type="button" class="btn-rm-img" id="t-rm" onclick="rmImg('t',event)"><i class="fas fa-times"></i></button>
                        <img id="t-prev" class="img-preview" alt="">
                        <div class="img-placeholder" id="t-ph">
                            <i class="fas fa-camera"></i>
                            <span>Klik untuk upload foto</span>
                            <small>Foto akan ditampilkan di daftar dan kasir</small>
                        </div>
                    </div>
                </div>
                <div class="fg full">
                    <label>Nama Obat <span style="color:var(--red)">*</span></label>
                    <input type="text" id="t-nama" class="fi" placeholder="Cth: Paracetamol 500mg">
                </div>
                <div class="fg">
                    <label>Kategori <span style="color:var(--red)">*</span></label>
                    <select id="t-kat" class="fs">
                        <option value="">-- Pilih --</option>
                        <?php foreach ($kategoris as $k): ?>
                            <option value="<?= $k['id_kategori'] ?>"><?= htmlspecialchars($k['nama_kategori']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Satuan</label>
                    <input type="text" id="t-satuan" class="fi" placeholder="Tablet, Kapsul, Botol...">
                </div>
                <div class="fg full">
                    <label>Harga Jual (Rp) <span style="color:var(--red)">*</span></label>
                    <input type="number" id="t-harga" class="fi" placeholder="0" min="0">
                </div>
                <div class="fg">
                    <label>Stok Awal</label>
                    <input type="number" id="t-stok" class="fi" value="0" min="0">
                </div>
                <div class="fg">
                    <label>Stok Minimum</label>
                    <input type="number" id="t-stokmin" class="fi" value="10" min="0">
                </div>
            </div>
            <div class="mfooter">
                <button class="mbtn secondary" onclick="closeModal('m-tambah')">Batal</button>
                <button class="mbtn primary" onclick="submitTambah()"><i class="fas fa-save"></i> Simpan Obat</button>
            </div>
        </div>
    </div>

    <!-- MODAL EDIT -->
    <div class="modal-overlay" id="m-edit">
        <div class="modal-box">
            <div class="modal-head">
                <h3><i class="fas fa-edit"></i> Edit Data Obat</h3>
                <button class="btn-close-modal" onclick="closeModal('m-edit')"><i class="fas fa-times"></i></button>
            </div>
            <input type="hidden" id="e-id">
            <div class="fg2">
                <div class="fg full">
                    <label>Foto Produk <span style="color:var(--muted);font-weight:400">(kosongkan jika tidak ingin mengubah)</span></label>
                    <div class="img-upload-area" id="e-area">
                        <input type="file" id="e-gambar" accept="image/*" onchange="prevImg('e')">
                        <button type="button" class="btn-rm-img" id="e-rm" onclick="rmImg('e',event)"><i class="fas fa-times"></i></button>
                        <img id="e-prev" class="img-preview" alt="">
                        <div class="img-placeholder" id="e-ph">
                            <i class="fas fa-camera"></i>
                            <span>Klik untuk ganti foto</span>
                            <small>JPG/PNG/WEBP · maks 2MB</small>
                        </div>
                    </div>
                </div>
                <div class="fg full">
                    <label>Nama Obat <span style="color:var(--red)">*</span></label>
                    <input type="text" id="e-nama" class="fi">
                </div>
                <div class="fg">
                    <label>Kategori <span style="color:var(--red)">*</span></label>
                    <select id="e-kat" class="fs">
                        <option value="">-- Pilih --</option>
                        <?php foreach ($kategoris as $k): ?>
                            <option value="<?= $k['id_kategori'] ?>"><?= htmlspecialchars($k['nama_kategori']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Satuan</label>
                    <input type="text" id="e-satuan" class="fi">
                </div>
                <div class="fg full">
                    <label>Harga Jual (Rp)</label>
                    <input type="number" id="e-harga" class="fi" min="0">
                </div>
                <div class="fg">
                    <label>Stok</label>
                    <input type="number" id="e-stok" class="fi" min="0">
                </div>
                <div class="fg">
                    <label>Stok Minimum</label>
                    <input type="number" id="e-stokmin" class="fi" min="0">
                </div>
            </div>
            <div class="mfooter">
                <button class="mbtn secondary" onclick="closeModal('m-edit')">Batal</button>
                <button class="mbtn primary" onclick="submitEdit()"><i class="fas fa-save"></i> Simpan Perubahan</button>
            </div>
        </div>
    </div>

    <!-- MODAL HAPUS -->
    <div class="modal-overlay" id="m-hapus">
        <div class="modal-box" style="width:380px;text-align:center">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--red-pale);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 14px">
                <i class="fas fa-trash"></i>
            </div>
            <div style="font-size:18px;font-weight:700;margin-bottom:6px">Hapus Obat?</div>
            <div style="font-size:13.5px;color:var(--muted);margin-bottom:20px" id="hapus-text">—</div>
            <div class="mfooter">
                <button class="mbtn secondary" onclick="closeModal('m-hapus')">Batal</button>
                <button class="mbtn danger" onclick="submitHapus()"><i class="fas fa-trash"></i> Ya, Hapus</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const BASE_URL = '<?= basename($_SERVER["PHP_SELF"]) ?>';
        const UPLOAD_URL = '<?= $uploadUrl ?>';

        function goPage(p) {
            const u = new URL(window.location.href);
            u.searchParams.set('page', p);
            window.location.href = u.toString()
        }

        // ── Image preview ──
        function prevImg(px) {
            const inp = document.getElementById(px + '-gambar');
            if (!inp.files || !inp.files[0]) return;
            if (inp.files[0].size > 2 * 1024 * 1024) {
                showToast('Ukuran maks 2MB!', true);
                inp.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = e => {
                const prev = document.getElementById(px + '-prev');
                const ph = document.getElementById(px + '-ph');
                const rm = document.getElementById(px + '-rm');
                const area = document.getElementById(px + '-area');
                prev.src = e.target.result;
                prev.classList.add('show');
                ph.classList.add('hidden');
                rm.classList.add('show');
                area.classList.add('has-img');
            };
            reader.readAsDataURL(inp.files[0]);
        }

        // Track apakah gambar dihapus saat edit
        let gambarDihapus = false;

        function rmImg(px, e) {
            e.stopPropagation();
            document.getElementById(px + '-gambar').value = '';
            const prev = document.getElementById(px + '-prev');
            const ph = document.getElementById(px + '-ph');
            const rm = document.getElementById(px + '-rm');
            const area = document.getElementById(px + '-area');
            prev.src = '';
            prev.classList.remove('show');
            ph.classList.remove('hidden');
            rm.classList.remove('show');
            area.classList.remove('has-img');
            // Tandai gambar dihapus (hanya berlaku saat mode edit)
            if (px === 'e') gambarDihapus = true;
        }

        // ── TAMBAH ──
        function submitTambah() {
            const nama = document.getElementById('t-nama').value.trim();
            const katId = document.getElementById('t-kat').value;
            const harga = document.getElementById('t-harga').value;
            if (harga < 0) {
                showToast('Harga tidak boleh minus!', true);
                return;
            }

            if (document.getElementById('t-stok').value < 0) {
                showToast('Stok tidak boleh minus!', true);
                return;
            }
            if (!nama || !katId || !harga) {
                showToast('Nama, kategori, dan harga wajib diisi!', true);
                return;
            }

            const fd = new FormData();
            fd.append('ajax_tambah', '1');
            fd.append('nama_obat', nama);
            fd.append('id_kategori', katId);
            fd.append('satuan', document.getElementById('t-satuan').value);
            fd.append('harga_jual', harga);
            fd.append('stok', document.getElementById('t-stok').value || 0);
            fd.append('stok_minimum', document.getElementById('t-stokmin').value || 10);
            const fi = document.getElementById('t-gambar');
            if (fi.files[0]) fd.append('gambar', fi.files[0]);

            fetch(BASE_URL, {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    closeModal('m-tambah');
                    showToast(d.message);
                    setTimeout(() => location.reload(), 900)
                } else showToast(d.message, true);
            }).catch(() => showToast('Koneksi gagal.', true));
        }

        // ── EDIT ──
        function openEdit(id) {
            gambarDihapus = false;
            rmImg('e', {
                stopPropagation: () => {}
            });
            fetch(`${BASE_URL}?get_obat=${id}`).then(r => r.json()).then(d => {
                if (d.error) {
                    showToast('Data tidak ditemukan', true);
                    return
                }
                document.getElementById('e-id').value = d.id_obat;
                document.getElementById('e-nama').value = d.nama_obat;
                document.getElementById('e-kat').value = d.id_kategori;
                document.getElementById('e-satuan').value = d.satuan || '';
                document.getElementById('e-harga').value = d.harga_jual || 0;
                document.getElementById('e-stok').value = d.stok || 0;
                document.getElementById('e-stokmin').value = d.stok_minimum || 10;
                if (d.gambar) {
                    const prev = document.getElementById('e-prev');
                    const ph = document.getElementById('e-ph');
                    const rm = document.getElementById('e-rm');
                    const area = document.getElementById('e-area');
                    prev.src = UPLOAD_URL + d.gambar;
                    prev.classList.add('show');
                    ph.classList.add('hidden');
                    rm.classList.add('show');
                    area.classList.add('has-img');
                }
                openModal('m-edit');
            }).catch(() => showToast('Gagal memuat data.', true));
        }

        function submitEdit() {
            const nama = document.getElementById('e-nama').value.trim();
            const katId = document.getElementById('e-kat').value;
            if (!nama || !katId) {
                showToast('Nama dan kategori wajib diisi!', true);
                return;
            }

            const fd = new FormData();
            fd.append('ajax_edit', '1');
            fd.append('id_obat', document.getElementById('e-id').value);
            fd.append('nama_obat', nama);
            fd.append('id_kategori', katId);
            fd.append('satuan', document.getElementById('e-satuan').value);
            fd.append('harga_jual', document.getElementById('e-harga').value || 0);
            fd.append('stok', document.getElementById('e-stok').value || 0);
            fd.append('stok_minimum', document.getElementById('e-stokmin').value || 10);
            const fi = document.getElementById('e-gambar');
            if (fi.files[0]) fd.append('gambar', fi.files[0]);
            if (gambarDihapus) fd.append('hapus_gambar', '1');

            fetch(BASE_URL, {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    closeModal('m-edit');
                    showToast(d.message);
                    setTimeout(() => location.reload(), 900)
                } else showToast(d.message, true);
            }).catch(() => showToast('Koneksi gagal.', true));
        }

        // ── HAPUS ──
        let hapusId = null;

        function confirmHapus(id, nama) {
            hapusId = id;
            document.getElementById('hapus-text').textContent = `"${nama}" akan dihapus permanen termasuk fotonya.`;
            openModal('m-hapus');
        }

        function submitHapus() {
            const fd = new FormData();
            fd.append('ajax_hapus', '1');
            fd.append('id_obat', hapusId);
            fetch(BASE_URL, {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(d => {
                closeModal('m-hapus');
                if (d.success) {
                    showToast(d.message);
                    const row = document.getElementById('tr-' + hapusId);
                    if (row) {
                        row.style.opacity = '0';
                        row.style.transition = 'opacity .3s';
                        setTimeout(() => row.remove(), 300)
                    }
                } else showToast(d.message, true);
            }).catch(() => showToast('Koneksi gagal.', true));
        }

        // ── EXPORT CSV ──
        function exportCSV() {
            const rows = [
                ['ID Obat', 'Nama Obat', 'Kategori', 'Harga Jual', 'Stok']
            ];
            document.querySelectorAll('#main-table tbody tr').forEach(tr => {
                const c = tr.querySelectorAll('td');
                if (c.length < 5) return;
                rows.push([c[0].textContent.trim(), c[1].textContent.trim(), c[2].textContent.trim(), c[3].textContent.trim(), c[4].textContent.trim()]);
            });
            const csv = rows.map(r => r.map(c => `"${c}"`).join(',')).join('\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            }));
            a.download = 'data_obat_' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
        }

        function openModal(id) {
            document.getElementById(id).classList.add('show')
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show')
        }
        document.querySelectorAll('.modal-overlay').forEach(o => {
            o.addEventListener('click', e => {
                if (e.target === o) o.classList.remove('show')
            });
        });

        function showToast(msg, error = false) {
            const t = document.getElementById('toast');
            t.innerHTML = `<i class="fas fa-${error?'exclamation-circle':'check-circle'}"></i> ${msg}`;
            t.className = 'toast show' + (error ? ' error' : '');
            setTimeout(() => t.className = 'toast', 2800);
        }
        // ── Dropdown user (klik avatar untuk buka/tutup) ──
        function toggleDropdown() {
            var menu = document.getElementById('ddmenu');
            if (menu.style.display === 'block') {
                menu.style.display = 'none';
            } else {
                menu.style.display = 'block';
            }
        }

        // Klik di luar dropdown = tutup otomatis
        document.addEventListener('click', function(e) {
            var wrap = document.getElementById('ddwrap');
            if (wrap && !wrap.contains(e.target)) {
                document.getElementById('ddmenu').style.display = 'none';
            }
        });
    </script>
</body>

</html>