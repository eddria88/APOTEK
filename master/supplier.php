<?php
session_start();
require_once "../koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SESSION['role'] != "admin" && $_SESSION['role'] != "owner") {
    header("Location: ../dashboard.php");
    exit;
}

$username  = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user      = mysqli_fetch_assoc($queryUser);

// AJAX: Tambah
if (isset($_POST['ajax_tambah'])) {
    header('Content-Type: application/json');
    ob_start();
    $nama   = mysqli_real_escape_string($conn, $_POST['nama_supplier'] ?? '');
    $alamat = mysqli_real_escape_string($conn, $_POST['alamat'] ?? '');
    $telp   = mysqli_real_escape_string($conn, $_POST['no_telp'] ?? '');
    $email  = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Aktif');
    if (empty($nama)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Nama supplier wajib diisi!']);
        exit;
    }

    // Cek duplikat
    $cek = mysqli_query($conn, "SELECT * FROM supplier WHERE nama_supplier='$nama'");
    if (mysqli_num_rows($cek) > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Supplier sudah ada!'
        ]);
        exit;
    }

    $ok  = mysqli_query($conn, "INSERT INTO supplier (nama_supplier,alamat,no_telp,email,status) VALUES ('$nama','$alamat','$telp','$email','$status')");
    $nid = mysqli_insert_id($conn);
    ob_end_clean();
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Supplier berhasil ditambahkan!', 'id' => $nid, 'nama' => htmlspecialchars($nama), 'alamat' => htmlspecialchars($alamat), 'telp' => htmlspecialchars($telp), 'email' => htmlspecialchars($email), 'status' => $status]
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Edit
if (isset($_POST['ajax_edit'])) {
    header('Content-Type: application/json');
    ob_start();
    $id     = (int)($_POST['id_supplier'] ?? 0);
    $nama   = mysqli_real_escape_string($conn, $_POST['nama_supplier'] ?? '');
    $alamat = mysqli_real_escape_string($conn, $_POST['alamat'] ?? '');
    $telp   = mysqli_real_escape_string($conn, $_POST['no_telp'] ?? '');
    $email  = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Aktif');
    if (empty($nama)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Nama supplier wajib diisi!']);
        exit;
    }
    $ok = mysqli_query($conn, "UPDATE supplier SET nama_supplier='$nama',alamat='$alamat',no_telp='$telp',email='$email',status='$status' WHERE id_supplier='$id'");
    ob_end_clean();
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Supplier berhasil diperbarui!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Hapus
if (isset($_POST['ajax_hapus'])) {
    header('Content-Type: application/json');
    ob_start();
    $id  = (int)($_POST['id_supplier'] ?? 0);
    $cek = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM pembelian WHERE id_supplier='$id'"));
    if ($cek['c'] > 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Supplier masih memiliki ' . $cek['c'] . ' riwayat pembelian!']);
        exit;
    }
    $ok = mysqli_query($conn, "DELETE FROM supplier WHERE id_supplier='$id'");
    ob_end_clean();
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Supplier berhasil dihapus!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Get single
if (isset($_GET['get_supplier'])) {
    header('Content-Type: application/json');
    ob_start();
    $id  = (int)$_GET['get_supplier'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM supplier WHERE id_supplier='$id'"));
    ob_end_clean();
    echo json_encode($row ?: ['error' => 'Not found']);
    exit;
}

// Auto-add kolom status
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM supplier LIKE 'status'");
if (mysqli_num_rows($colCheck) === 0)
    mysqli_query($conn, "ALTER TABLE supplier ADD COLUMN status VARCHAR(20) DEFAULT 'Aktif'");

// Pagination + filter
$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$search     = mysqli_real_escape_string($conn, $_GET['search'] ?? '');
$filterStat = mysqli_real_escape_string($conn, $_GET['status'] ?? '');

$where = "WHERE 1=1";
if ($search)     $where .= " AND (nama_supplier LIKE '%$search%' OR alamat LIKE '%$search%' OR no_telp LIKE '%$search%' OR email LIKE '%$search%')";
if ($filterStat) $where .= " AND status='$filterStat'";

$totalRow  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM supplier $where"))['c'];
$totalPage = max(1, ceil($totalRow / $perPage));
$offset    = ($page - 1) * $perPage;

$dataResult = mysqli_query($conn, "SELECT * FROM supplier $where ORDER BY id_supplier DESC LIMIT $perPage OFFSET $offset");
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Data Supplier — Apotek</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/supplier.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <!-- TOP NAV -->
    <nav class="topnav">
        <a href="../dashboard.php" class="sb-brand">
            <img src="../uploads/logo.png" alt="Logo Apotek" style="height: 125px;" class="logo">
        </a>
        <div class="breadcrumb">
            <i class="fas fa-chevron-right"></i>
            <span class="current">Data Supplier</span>
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

        <!-- SIDEBAR -->
        <aside class="sidebar">
<<<<<<< HEAD
              <?php if ($user['role'] != 'admin'): ?>
            <div class="sb-sec">Core</div>
            <a class="sb-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
=======
            <?php if ($user['role'] != 'admin'): ?>
                <div class="sb-sec">Core</div>
                <a class="sb-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
>>>>>>> 8d09b546e690e945f8c9d996dca731ad8a4e7666
            <?php endif; ?>
            <div class="sb-sec">Master Data</div>
            <a class="sb-link" href="kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <?php if ($user['role'] != 'kasir'): ?>
<<<<<<< HEAD
            <a class="sb-link active" href="supplier.php"><i class="fas fa-truck"></i> Supplier</a>
=======
                <a class="sb-link active" href="supplier.php"><i class="fas fa-truck"></i> Supplier</a>
>>>>>>> 8d09b546e690e945f8c9d996dca731ad8a4e7666
            <?php endif; ?>
            <a class="sb-link" href="obat.php"><i class="fas fa-pills"></i> Obat</a>
            <a class="sb-link" href="member.php"><i class="fas fa-user-friends"></i> Member</a>
            <?php if ($user['role'] == 'owner'): ?>
<<<<<<< HEAD
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
=======
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
>>>>>>> 8d09b546e690e945f8c9d996dca731ad8a4e7666
            <?php endif; ?>
            <div class="sb-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <div class="main-content">

            <!-- PAGE HEADER -->
            <div class="page-header">
                <div>
                    <h2>Data Supplier</h2>
                    <p>Kelola informasi pemasok obat</p>
                </div>
                <button class="btn-add" onclick="openTambah()">
                    <i class="fas fa-plus"></i> Tambah Supplier
                </button>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">

                <!-- Toolbar — sama seperti obat.php -->
                <form method="GET" id="ff">
                    <div class="table-toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search"
                                placeholder="Cari nama, alamat, telepon, email..."
                                value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                onchange="document.getElementById('ff').submit()">
                        </div>
                        <div class="toolbar-right">
                            <select name="status" class="select-sm" onchange="document.getElementById('ff').submit()">
                                <option value="">Semua Status</option>
                                <option value="Aktif" <?= ($filterStat === 'Aktif'   ? 'selected' : '') ?>>Aktif</option>
                                <option value="Nonaktif" <?= ($filterStat === 'Nonaktif' ? 'selected' : '') ?>>Nonaktif</option>
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
                                <th>ID</th>
                                <th>Nama Supplier</th>
                                <th>Alamat</th>
                                <th>Telepon</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th class="center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbody">
                            <?php
                            $rows = [];
                            while ($row = mysqli_fetch_assoc($dataResult)) $rows[] = $row;
                            if (!$rows): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <i class="fas fa-truck"></i>
                                            <p>Belum ada data supplier ditemukan</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: foreach ($rows as $row):
                                    $initials = strtoupper(substr($row['nama_supplier'], 0, 2));
                                    $status   = $row['status'] ?? 'Aktif';
                                    $sCls     = strtolower($status) === 'aktif' ? 'badge-aktif' : 'badge-nonaktif';
                                    $idDisplay = 'SUP-' . str_pad($row['id_supplier'], 3, '0', STR_PAD_LEFT);
                                ?>
                                    <tr id="tr-<?= $row['id_supplier'] ?>">
                                        <td><span class="id-mono"><?= $idDisplay ?></span></td>
                                        <td>
                                            <span class="td-name"><?= htmlspecialchars($row['nama_supplier']) ?></span>
                                        </td>
                                        <td class="td-muted"><?= htmlspecialchars($row['alamat'] ?: '—') ?></td>
                                        <td class="td-muted"><?= htmlspecialchars($row['no_telp'] ?: '—') ?></td>
                                        <td class="td-muted"><?= htmlspecialchars($row['email'] ?: '—') ?></td>
                                        <td>
                                            <span class="badge-status <?= $sCls ?>">
                                                <span class="badge-dot-s"></span><?= $status ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-cell">
                                                <button class="btn-icon blue" title="Edit" onclick="openEdit(<?= $row['id_supplier'] ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn-icon red" title="Hapus" onclick="confirmHapus(<?= $row['id_supplier'] ?>,'<?= addslashes($row['nama_supplier']) ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination — sama seperti obat.php -->
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
                <h3><i class="fas fa-plus-circle"></i> Tambah Supplier</h3>
                <button class="btn-close" onclick="closeModal('m-tambah')"><i class="fas fa-times"></i></button>
            </div>
            <div class="fg2">
                <div class="fg full">
                    <label>Nama Supplier <span style="color:var(--red)">*</span></label>
                    <input type="text" id="t-nama" class="fi" placeholder="Cth: PT. Kimia Farma" required>
                </div>
                <div class="fg full">
                    <label>Alamat <span style="color:var(--red)">*</span></label>
                    <input type="text" id="t-alamat" class="fi" placeholder="Jl. Contoh No. 1, Kota" required>
                </div>
                <div class="fg">
                    <label>No. Telepon <span style="color:var(--red)">*</span></label>
                    <input type="text" id="t-telp" class="fi" placeholder="021-xxxxxxx" required>
                </div>
                <div class="fg">
                    <label>Email <span style="color:var(--red)">*</span></label>
                    <input type="email" id="t-email" class="fi" placeholder="email@domain.com" required>
                </div>
                <div class="fg full">
                    <label>Status</label>
                    <select id="t-status" class="fs">
                        <option value="Aktif">Aktif</option>
                        <option value="Nonaktif">Nonaktif</option>
                    </select>
                </div>
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
            <div class="modal-head">
                <h3><i class="fas fa-edit"></i> Edit Supplier</h3>
                <button class="btn-close" onclick="closeModal('m-edit')"><i class="fas fa-times"></i></button>
            </div>
            <input type="hidden" id="e-id">
            <div class="fg2">
                <div class="fg full">
                    <label>Nama Supplier <span style="color:var(--red)">*</span></label>
                    <input type="text" id="e-nama" class="fi">
                </div>
                <div class="fg full">
                    <label>Alamat</label>
                    <input type="text" id="e-alamat" class="fi">
                </div>
                <div class="fg">
                    <label>No. Telepon</label>
                    <input type="text" id="e-telp" class="fi">
                </div>
                <div class="fg">
                    <label>Email</label>
                    <input type="email" id="e-email" class="fi">
                </div>
                <div class="fg full">
                    <label>Status</label>
                    <select id="e-status" class="fs">
                        <option value="Aktif">Aktif</option>
                        <option value="Nonaktif">Nonaktif</option>
                    </select>
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
        <div class="modal-box" style="text-align:center">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--red-pale);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:22px;margin:0 auto 14px">
                <i class="fas fa-trash"></i>
            </div>
            <div style="font-size:18px;font-weight:700;margin-bottom:6px">Hapus Supplier?</div>
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

        function goPage(p) {
            const u = new URL(window.location.href);
            u.searchParams.set('page', p);
            window.location.href = u.toString();
        }

        // ── TAMBAH ──
        function openTambah() {
            ['t-nama', 't-alamat', 't-telp', 't-email'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('t-status').value = 'Aktif';
            openModal('m-tambah');
            setTimeout(() => document.getElementById('t-nama').focus(), 150);
        }

        function submitTambah() {

            const nama = document.getElementById('t-nama').value.trim();
            const alamat = document.getElementById('t-alamat').value.trim();
            const telp = document.getElementById('t-telp').value.trim();
            const email = document.getElementById('t-email').value.trim();

            // NAMA WAJIB DIISI
            if (!nama || !alamat || !telp || !email) {
                showToast('Semua field wajib diisi!', true);
                return;
            }

            // VALIDASI TELEPON (HANYA ANGKA)
            if (telp && !/^[0-9]+$/.test(telp)) {
                showToast('Nomor telepon hanya boleh angka!', true);
                document.getElementById('t-telp').focus();
                return;
            }

            // VALIDASI FORMAT EMAIL
            const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!pattern.test(email)) {
                showToast('Format email tidak valid!', true);
                document.getElementById('t-email').focus();
                return;
            }

            const fd = new FormData();
            fd.append('ajax_tambah', '1');
            fd.append('nama_supplier', nama);
            fd.append('alamat', document.getElementById('t-alamat').value);
            fd.append('no_telp', telp);
            fd.append('email', email);
            fd.append('status', document.getElementById('t-status').value);

            fetch(BASE_URL, {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    closeModal('m-tambah');
                    showToast(d.message);
                    appendRow(d);
                } else {
                    showToast(d.message, true);
                }
            }).catch(() => showToast('Koneksi gagal.', true));
        }

        function appendRow(d) {
            const tbody = document.getElementById('tbody');
            const empty = tbody.querySelector('.empty-state');
            if (empty) empty.closest('tr').remove();
            const initials = d.nama.substring(0, 2).toUpperCase();
            const sCls = d.status === 'Aktif' ? 'badge-aktif' : 'badge-nonaktif';
            const idDisplay = 'SUP-' + String(d.id).padStart(3, '0');
            const tr = document.createElement('tr');
            tr.id = 'tr-' + d.id;
            tr.style.cssText = 'opacity:0;transition:opacity .3s';
            tr.innerHTML = `
            <td><span class="id-mono">${idDisplay}</span></td>
            <td><span class="td-name">${d.nama}</span></td>
            <td class="td-muted">${d.alamat||'—'}</td>
            <td class="td-muted">${d.telp||'—'}</td>
            <td class="td-muted">${d.email||'—'}</td>
            <td><span class="badge-status ${sCls}"><span class="badge-dot-s"></span>${d.status}</span></td>
            <td>
                <div class="action-cell">
                <button class="btn-icon blue" onclick="openEdit(${d.id})"><i class="fas fa-edit"></i></button>
                <button class="btn-icon red" onclick="confirmHapus(${d.id},'${d.nama.replace(/'/g," \\'")}')"><i
                class="fas fa-trash"></i></button>
            </div>
            </td>`;
            tbody.prepend(tr);
            requestAnimationFrame(() => tr.style.opacity = '1');
        }

        // ── EDIT ──
        function openEdit(id) {
            fetch(`${BASE_URL}?get_supplier=${id}`).then(r => r.json()).then(d => {
                if (d.error) {
                    showToast('Data tidak ditemukan', true);
                    return;
                }
                document.getElementById('e-id').value = d.id_supplier;
                document.getElementById('e-nama').value = d.nama_supplier;
                document.getElementById('e-alamat').value = d.alamat || '';
                document.getElementById('e-telp').value = d.no_telp || '';
                document.getElementById('e-email').value = d.email || '';
                document.getElementById('e-status').value = d.status || 'Aktif';
                openModal('m-edit');
                setTimeout(() => document.getElementById('e-nama').focus(), 150);
            }).catch(() => showToast('Gagal memuat data.', true));
        }

        function submitEdit() {
            const nama = document.getElementById('e-nama').value.trim();
            if (!nama) {
                showToast('Nama supplier wajib diisi!', true);
                return;
            }
            const fd = new FormData();
            fd.append('ajax_edit', '1');
            fd.append('id_supplier', document.getElementById('e-id').value);
            fd.append('nama_supplier', nama);
            fd.append('alamat', document.getElementById('e-alamat').value);
            fd.append('no_telp', document.getElementById('e-telp').value);
            fd.append('email', document.getElementById('e-email').value);
            fd.append('status', document.getElementById('e-status').value);
            fetch(BASE_URL, {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    closeModal('m-edit');
                    showToast(d.message);
                    setTimeout(() => location.reload(), 900);
                } else showToast(d.message, true);
            }).catch(() => showToast('Koneksi gagal.', true));
        }

        // ── HAPUS ──
        let hapusId = null;

        function confirmHapus(id, nama) {
            hapusId = id;
            document.getElementById('hapus-text').textContent = `"${nama}" akan dihapus permanen.`;
            openModal('m-hapus');
        }

        function submitHapus() {
            const fd = new FormData();
            fd.append('ajax_hapus', '1');
            fd.append('id_supplier', hapusId);
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
                        setTimeout(() => row.remove(), 300);
                    }
                } else showToast(d.message, true);
            }).catch(() => showToast('Koneksi gagal.', true));
        }

        // ── EXPORT CSV ──
        function exportCSV() {
            const rows = [
                ['ID', 'Nama Supplier', 'Alamat', 'Telepon', 'Email', 'Status']
            ];
            document.querySelectorAll('#main-table tbody tr').forEach(tr => {
                const c = tr.querySelectorAll('td');
                if (c.length < 6) return;
                rows.push([c[0].textContent.trim(), c[1].textContent.trim(), c[2].textContent.trim(), c[3].textContent.trim(), c[4].textContent.trim(), c[5].textContent.trim()]);
            });
            const csv = rows.map(r => r.map(c => `"${c}"`).join(',')).join('\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            }));
            a.download = 'data_supplier_' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
        }

        function openModal(id) {
            document.getElementById(id).classList.add('show');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }
        document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => {
            if (e.target === o) o.classList.remove('show')
        }));

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