<?php
session_start();
require_once "../koneksi.php";

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit;
}

if($_SESSION['role'] != "admin" && $_SESSION['role'] != "kasir" && $_SESSION['role'] != "owner"){
    header("Location: ../dashboard.php");
    exit;
}

if ($_SESSION['role'] == "owner" && (isset($_POST['ajax_tambah']) || isset($_POST['ajax_edit']) || isset($_POST['ajax_hapus']))) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Owner tidak berhak mengubah data.']);
    exit;
}

$username = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user = mysqli_fetch_assoc($queryUser);

// AJAX: Tambah
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

    // CEK KATEGORI DOUBLE
    $cek = mysqli_query($conn, "SELECT * FROM kategori WHERE nama_kategori='$nama'");
    if (mysqli_num_rows($cek) > 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Kategori sudah ada!']);
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

    // CEK NAMA KATEGORI SUDAH DIPAKAI KATEGORI LAIN
    $cek = mysqli_query($conn, "SELECT * FROM kategori WHERE nama_kategori='$nama' AND id_kategori != '$id'");
    if (mysqli_num_rows($cek) > 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Kategori dengan nama tersebut sudah ada!']);
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
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/kategori.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            <span class="current">Data Kategori</span>
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
<<<<<<< HEAD
            <div class="sb-sec">Core</div>
            <a class="sb-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
=======
            <?php if ($user['role'] != 'admin'): ?>
                <div class="sb-sec">Core</div>
                <a class="sb-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <?php endif; ?>
>>>>>>> 8d09b546e690e945f8c9d996dca731ad8a4e7666
            <div class="sb-sec">Master Data</div>
            <a class="sb-link active" href="kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <?php if ($user['role'] != 'kasir'): ?>
            <a class="sb-link" href="supplier.php"><i class="fas fa-truck"></i> Supplier</a>
            <?php endif; ?>
            <a class="sb-link" href="obat.php"><i class="fas fa-pills"></i> Obat</a>
            <a class="sb-link" href="member.php"><i class="fas fa-user-friends"></i> Member</a>
            <?php if ($user['role'] == 'owner'): ?>
<<<<<<< HEAD
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
            <div class="page-header">
                <div>
                    <h2>Kategori Obat</h2>
                    <p>Kelola kategori produk apotek</p>
                </div>
<<<<<<< HEAD
                <?php if ($user['role'] != 'owner'): ?>
=======
>>>>>>> 8d09b546e690e945f8c9d996dca731ad8a4e7666
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
<<<<<<< HEAD
                        <?php if ($user['role'] != 'owner'): ?>
                    <div class="card-actions">
=======
                        <div class="card-actions">
>>>>>>> 8d09b546e690e945f8c9d996dca731ad8a4e7666
                            <button class="cact edit" title="Edit"
                                onclick="openEdit(<?= $k['id_kategori'] ?>,'<?= addslashes(htmlspecialchars($k['nama_kategori'])) ?>',event)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="cact del" title="Hapus"
                                onclick="confirmHapus(<?= $k['id_kategori'] ?>,'<?= addslashes(htmlspecialchars($k['nama_kategori'])) ?>',event)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
<<<<<<< HEAD
                    <?php endif; ?>
=======
>>>>>>> 8d09b546e690e945f8c9d996dca731ad8a4e7666
                        <div class="cat-icon-wrap <?= $color ?>"><i class="fas <?= $icon ?>"></i></div>
                        <div class="cat-name"><?= htmlspecialchars($k['nama_kategori']) ?></div>
                        <div class="cat-count"><?= $k['jumlah_produk'] ?> Produk</div>
                    </div>
                <?php endforeach; ?>

                <!-- Card Tambah Baru -->
<<<<<<< HEAD
                <?php if ($user['role'] != 'owner'): ?>
=======
>>>>>>> 8d09b546e690e945f8c9d996dca731ad8a4e7666
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

        // Dropdown user — klik avatar untuk buka/tutup
        function toggleDropdown() {
            var menu = document.getElementById('ddmenu');
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
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