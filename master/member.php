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

$username  = $_SESSION['user'];
$queryUser = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
$user      = mysqli_fetch_assoc($queryUser);
$isOwner   = $user['role'] === 'owner';

// Pastikan tabel member tersedia
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS member (
    id_member INT AUTO_INCREMENT PRIMARY KEY,
    nama_lengkap VARCHAR(255) NOT NULL,
    no_hp VARCHAR(50) NOT NULL,
    alamat TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// AJAX: Tambah
if (isset($_POST['ajax_tambah'])) {
    header('Content-Type: application/json');
    if ($isOwner) {
        echo json_encode(['success' => false, 'message' => 'Owner tidak memiliki izin mengubah data.']);
        exit;
    }
    $nama   = mysqli_real_escape_string($conn, trim($_POST['nama_lengkap'] ?? ''));
    $hp     = mysqli_real_escape_string($conn, trim($_POST['no_hp'] ?? ''));
    $alamat = mysqli_real_escape_string($conn, trim($_POST['alamat'] ?? ''));
    if ($nama === '' || $hp === '') {
        echo json_encode(['success' => false, 'message' => 'Nama lengkap dan No. HP wajib diisi!']);
        exit;
    }

    // Cek duplikat
    $cek = mysqli_query($conn, "SELECT * FROM member WHERE nama_lengkap='$nama'");
    if (mysqli_num_rows($cek) > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Member sudah ada!'
        ]);
        exit;
    }

    $ok = mysqli_query($conn, "INSERT INTO member (nama_lengkap,no_hp,alamat) VALUES ('$nama','$hp','$alamat')");
    $id = mysqli_insert_id($conn);
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Member berhasil ditambahkan!', 'id' => $id, 'nama' => htmlspecialchars($nama), 'hp' => htmlspecialchars($hp), 'alamat' => htmlspecialchars($alamat)]
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Edit
if (isset($_POST['ajax_edit'])) {
    header('Content-Type: application/json');
    if ($isOwner) {
        echo json_encode(['success' => false, 'message' => 'Owner tidak memiliki izin mengubah data.']);
        exit;
    }
    $id     = (int)($_POST['id_member'] ?? 0);
    $nama   = mysqli_real_escape_string($conn, trim($_POST['nama_lengkap'] ?? ''));
    $hp     = mysqli_real_escape_string($conn, trim($_POST['no_hp'] ?? ''));
    $alamat = mysqli_real_escape_string($conn, trim($_POST['alamat'] ?? ''));
    if ($id <= 0 || $nama === '' || $hp === '') {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
        exit;
    }
    $ok = mysqli_query($conn, "UPDATE member SET nama_lengkap='$nama',no_hp='$hp',alamat='$alamat' WHERE id_member='$id'");
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Data member berhasil diperbarui!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Hapus
if (isset($_POST['ajax_hapus'])) {
    header('Content-Type: application/json');
    if ($isOwner) {
        echo json_encode(['success' => false, 'message' => 'Owner tidak memiliki izin mengubah data.']);
        exit;
    }
    $id = (int)($_POST['id_member'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID member tidak valid.']);
        exit;
    }
    $ok = mysqli_query($conn, "DELETE FROM member WHERE id_member='$id'");
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Member berhasil dihapus!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// Pagination + filter
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$search  = mysqli_real_escape_string($conn, $_GET['search'] ?? '');

$where = "WHERE 1=1";
if ($search) $where .= " AND (nama_lengkap LIKE '%$search%' OR no_hp LIKE '%$search%' OR alamat LIKE '%$search%')";

$totalRow  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM member $where"))['c'];
$totalPage = max(1, ceil($totalRow / $perPage));
$offset    = ($page - 1) * $perPage;

$query   = mysqli_query($conn, "SELECT * FROM member $where ORDER BY id_member DESC LIMIT $perPage OFFSET $offset");
$members = [];
while ($r = mysqli_fetch_assoc($query)) $members[] = $r;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Data Member — Apotek</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/member.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
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
            <span class="current">Data Member</span>
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
            <a class="sb-link" href="obat.php"><i class="fas fa-pills"></i> Obat</a>
            <a class="sb-link active" href="member.php"><i class="fas fa-user-friends"></i> Member</a>
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
            <a class="sb-link" href="../transaksi/pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
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
                    <h2>Data Member</h2>
                    <p>Kelola data member apotek</p>
                </div>
                <?php if (!$isOwner): ?>
                <button class="btn-add" onclick="openModal('m-tambah')">
                    <i class="fas fa-plus"></i> Tambah Member
                </button>
                <?php endif; ?>
            </div>

            <div class="table-card">
                <form method="GET" id="ff">
                    <div class="table-toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search"
                                placeholder="Cari nama, no HP, atau alamat..."
                                value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                onchange="document.getElementById('ff').submit()">
                        </div>
                        <div class="toolbar-right">
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
                                <th>ID Member</th>
                                <th>Nama Lengkap</th>
                                <th>No. HP</th>
                                <th>Alamat</th>
                                <th>Terdaftar</th>
                                <th class="center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($members)): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <i class="fas fa-user-friends"></i>
                                            <p>Tidak ada member ditemukan</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: foreach ($members as $m):
                                    $idDisplay = 'MBR-' . str_pad($m['id_member'], 3, '0', STR_PAD_LEFT);
                                    $inisial   = strtoupper(substr($m['nama_lengkap'], 0, 1));
                                    $tgl       = date('d M Y', strtotime($m['created_at']));
                                ?>
                                    <tr id="row-<?= $m['id_member'] ?>">
                                        <td><span class="id-mono"><?= $idDisplay ?></span></td>
                                        <td>
                                            <span class="td-bold"><?= htmlspecialchars($m['nama_lengkap']) ?></span>
                                        </td>
                                        <td class="td-muted"><?= htmlspecialchars($m['no_hp']) ?></td>
                                        <td class="td-muted"><?= htmlspecialchars($m['alamat'] ?: '—') ?></td>
                                        <td class="td-muted"><?= $tgl ?></td>
                                        <td>
                                            <div class="action-cell">
                                            <?php if (!$isOwner): ?>
                                            <button class="btn-icon blue" title="Edit"
                                                onclick="openEdit(<?= $m['id_member'] ?>,'<?= addslashes(htmlspecialchars($m['nama_lengkap'])) ?>','<?= addslashes(htmlspecialchars($m['no_hp'])) ?>','<?= addslashes(htmlspecialchars($m['alamat'])) ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon red" title="Hapus"
                                                onclick="confirmHapus(<?= $m['id_member'] ?>,'<?= addslashes(htmlspecialchars($m['nama_lengkap'])) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
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
                <h3><i class="fas fa-user-plus"></i> Tambah Member</h3>
                <button class="btn-close-modal" onclick="closeModal('m-tambah')"><i class="fas fa-times"></i></button>
            </div>
            <div class="fg">
                <label>Nama Lengkap <span style="color:var(--red)">*</span></label>
                <input type="text" id="t-nama" class="fi" placeholder="Masukkan nama lengkap">
            </div>
            <div class="fg">
                <label>No. HP <span style="color:var(--red)">*</span></label>
                <input type="text" id="t-telp" class="fi" placeholder="08xxxxxx">
            </div>
            <div class="fg">
                <label>Alamat <span style="color:var(--muted);font-weight:400">(opsional)</span></label>
                <input type="text" id="t-alamat" class="fi" placeholder="Alamat member">
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
                <h3><i class="fas fa-edit"></i> Edit Member</h3>
                <button class="btn-close-modal" onclick="closeModal('m-edit')"><i class="fas fa-times"></i></button>
            </div>
            <input type="hidden" id="e-id">
            <div class="fg">
                <label>Nama Lengkap <span style="color:var(--red)">*</span></label>
                <input type="text" id="e-nama" class="fi">
            </div>
            <div class="fg">
                <label>No. HP <span style="color:var(--red)">*</span></label>
                <input type="text" id="e-hp" class="fi">
            </div>
            <div class="fg">
                <label>Alamat</label>
                <input type="text" id="e-alamat" class="fi">
            </div>
            <div class="mfooter">
                <button class="mbtn secondary" onclick="closeModal('m-edit')">Batal</button>
                <button class="mbtn primary" onclick="submitEdit()"><i class="fas fa-save"></i> Simpan</button>
            </div>
        </div>
    </div>

    <!-- MODAL HAPUS -->
    <div class="modal-overlay" id="m-hapus">
        <div class="modal-box" style="width:380px;text-align:center">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--red-pale);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 14px">
                <i class="fas fa-trash"></i>
            </div>
            <div style="font-size:18px;font-weight:700;margin-bottom:6px">Hapus Member?</div>
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
        let editId = null;
        let hapusId = null;

        function goPage(p) {
            const u = new URL(window.location.href);
            u.searchParams.set('page', p);
            window.location.href = u.toString();
        }

        function openModal(id) {
            document.getElementById(id).classList.add('show');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }
        document.querySelectorAll('.modal-overlay').forEach(o => {
            o.addEventListener('click', e => {
                if (e.target === o) o.classList.remove('show');
            });
        });

        function showToast(msg, error = false) {
            const t = document.getElementById('toast');
            t.innerHTML = `<i class="fas fa-${error?'exclamation-circle':'check-circle'}"></i> ${msg}`;
            t.className = 'toast show' + (error ? ' error' : '');
            setTimeout(() => t.className = 'toast', 2800);
        }

        // ── TAMBAH ──
        function submitTambah() {
            const nama = document.getElementById('t-nama').value.trim();
            const hp = document.getElementById('t-telp').value.trim();
            const alamat = document.getElementById('t-alamat').value.trim();

            if (!nama || !hp) {
                showToast('Nama dan No. HP wajib diisi!', true);
                return;
            }

            // VALIDASI TELEPON
            if (hp && !/^[0-9]+$/.test(hp)) {
                showToast('Nomor telepon hanya boleh angka!', true);
                document.getElementById('t-telp').focus();
                return;
            }

            const fd = new FormData();
            fd.append('ajax_tambah', '1');
            fd.append('nama_lengkap', nama);
            fd.append('no_hp', hp);
            fd.append('alamat', alamat);

            fetch(BASE_URL, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        closeModal('m-tambah');
                        showToast(d.message);
                        setTimeout(() => location.reload(), 900);
                    } else {
                        showToast(d.message, true);
                    }
                })
                .catch(() => showToast('Koneksi gagal.', true));
        }

        // ── EDIT ──
        function openEdit(id, nama, hp, alamat) {
            editId = id;
            document.getElementById('e-id').value = id;
            document.getElementById('e-nama').value = nama;
            document.getElementById('e-hp').value = hp;
            document.getElementById('e-alamat').value = alamat;
            openModal('m-edit');
        }

        function submitEdit() {
            const nama = document.getElementById('e-nama').value.trim();
            const hp = document.getElementById('e-hp').value.trim();
            const alamat = document.getElementById('e-alamat').value.trim();
            if (!nama || !hp) {
                showToast('Nama dan No. HP wajib diisi!', true);
                return;
            }
            const fd = new FormData();
            fd.append('ajax_edit', '1');
            fd.append('id_member', editId);
            fd.append('nama_lengkap', nama);
            fd.append('no_hp', hp);
            fd.append('alamat', alamat);
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
        function confirmHapus(id, nama) {
            hapusId = id;
            document.getElementById('hapus-text').textContent = `"${nama}" akan dihapus permanen.`;
            openModal('m-hapus');
        }

        function submitHapus() {
            const fd = new FormData();
            fd.append('ajax_hapus', '1');
            fd.append('id_member', hapusId);
            fetch(BASE_URL, {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(d => {
                closeModal('m-hapus');
                if (d.success) {
                    showToast(d.message);
                    const row = document.getElementById('row-' + hapusId);
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
                ['ID Member', 'Nama Lengkap', 'No. HP', 'Alamat', 'Terdaftar']
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
            a.download = 'data_member_' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
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