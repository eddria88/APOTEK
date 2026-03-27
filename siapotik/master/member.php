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
    $nama   = mysqli_real_escape_string($conn, trim($_POST['nama_lengkap'] ?? ''));
    $hp     = mysqli_real_escape_string($conn, trim($_POST['no_hp'] ?? ''));
    $alamat = mysqli_real_escape_string($conn, trim($_POST['alamat'] ?? ''));
    if ($nama === '' || $hp === '') {
        echo json_encode(['success' => false, 'message' => 'Nama lengkap dan No. HP wajib diisi!']);
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
    $id     = (int) ($_POST['id_member'] ?? 0);
    $nama   = mysqli_real_escape_string($conn, trim($_POST['nama_lengkap'] ?? ''));
    $hp     = mysqli_real_escape_string($conn, trim($_POST['no_hp'] ?? ''));
    $alamat = mysqli_real_escape_string($conn, trim($_POST['alamat'] ?? ''));
    if ($id <= 0 || $nama === '' || $hp === '') {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
        exit;
    }
    $ok = mysqli_query($conn, "UPDATE member SET nama_lengkap='$nama', no_hp='$hp', alamat='$alamat' WHERE id_member='$id'");
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Data member berhasil diperbarui!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Hapus
if (isset($_POST['ajax_hapus'])) {
    header('Content-Type: application/json');
    $id = (int) ($_POST['id_member'] ?? 0);
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

$search = trim($_GET['q'] ?? '');
$where  = '';
if ($search !== '') {
    $s     = mysqli_real_escape_string($conn, $search);
    $where = "WHERE nama_lengkap LIKE '%$s%' OR no_hp LIKE '%$s%' OR alamat LIKE '%$s%'";
}

$query   = mysqli_query($conn, "SELECT * FROM member $where ORDER BY id_member DESC");
$members = [];
while ($r = mysqli_fetch_assoc($query)) $members[] = $r;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Member — Apotek</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --green:#2d6a4f; --green-mid:#40916c; --green-light:#52b788;
    --green-pale:#d8f3dc; --green-btn:#1b4332;
    --bg:#f4f6f3; --surface:#fff; --border:#e0e6de;
    --text:#1a2e1a; --muted:#6b7e6b;
    --red:#e63946; --red-pale:#ffeef0;
    --blue:#1d6fa4; --blue-pale:#ddeeff;
    --amber:#e07b00; --amber-pale:#fff3e0;
    --purple:#7c3aed; --purple-pale:#f3eeff;
    --radius:16px; --shadow:0 2px 12px rgba(0,0,0,.07);
}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column}

/* ── Top Navigation Bar ── */
.topnav{background:var(--surface);border-bottom:1px solid var(--border);height:60px;display:flex;align-items:center;padding:0 28px;gap:16px;position:sticky;top:0;z-index:100;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.topnav-right{margin-left:auto;display:flex;align-items:center;gap:14px}
.icon-btn{width:38px;height:38px;border:1px solid var(--border);border-radius:11px;background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted);font-size:15px;position:relative;transition:border-color .2s,color .2s}
.icon-btn:hover{border-color:var(--green-light);color:var(--green)}
.notif-dot{position:absolute;top:7px;right:7px;width:7px;height:7px;background:var(--red);border-radius:50%;border:1.5px solid var(--surface)}
.user-info{display:flex;align-items:center;gap:10px}
.user-texts{text-align:right}
.user-texts .uname{font-size:14px;font-weight:700;line-height:1.2}
.user-texts .urole{font-size:11.5px;color:var(--muted)}
.user-avatar{width:38px;height:38px;border-radius:50%;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:800;cursor:pointer}
.ddwrap{position:relative}
.ddmenu{position:absolute;right:0;top:calc(100% + 8px);background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.1);min-width:180px;padding:8px;display:none;z-index:200}
.ddmenu a,.ddmenu span{display:block;padding:8px 12px;font-size:13px;color:var(--text);border-radius:8px;text-decoration:none}
.ddmenu a:hover{background:var(--green-pale);color:var(--green)}
.ddmenu .role-lbl{color:var(--muted);font-size:12px}
.ddmenu hr{border:none;border-top:1px solid var(--border);margin:4px 0}
.ddmenu .logout{color:var(--red)!important}
.ddmenu .logout:hover{background:var(--red-pale)!important}

/* ── Layout ── */
.app-body{display:flex;flex:1;overflow:hidden;height:calc(100vh - 60px)}
.sidebar{width:220px;min-width:220px;background:var(--surface);border-right:1px solid var(--border);overflow-y:auto;padding:16px 0;display:flex;flex-direction:column}
.sb-brand{display:flex;align-items:center;gap:8px;padding:4px 20px 16px;font-size:17px;font-weight:800;color:var(--green);letter-spacing:-.3px;text-decoration:none;border-bottom:1px solid var(--border);margin-bottom:8px}
.sb-brand i{color:var(--green-light)}
.sb-sec{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted);padding:8px 20px 4px}
.sb-link{display:flex;align-items:center;gap:10px;padding:9px 20px;font-size:13.5px;font-weight:500;color:var(--muted);text-decoration:none;transition:background .15s,color .15s}
.sb-link i{width:16px;text-align:center;font-size:13px}
.sb-link:hover{background:var(--green-pale);color:var(--green)}
.sb-link.active{background:var(--green-pale);color:var(--green);font-weight:700;border-right:3px solid var(--green)}
.sb-footer{margin-top:auto;padding:16px 20px;border-top:1px solid var(--border);font-size:12px;color:var(--muted)}
.sb-footer strong{display:block;color:var(--text);font-size:13px}

/* ── Konten ── */
.main-content{flex:1;overflow-y:auto;padding:28px;display:flex;flex-direction:column;gap:24px}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
.page-header h2{font-size:22px;font-weight:700}
.page-header p{font-size:13.5px;color:var(--muted);margin-top:3px}
.btn-add{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;border:none;background:var(--green);color:#fff;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;white-space:nowrap;transition:background .2s,transform .1s}
.btn-add:hover{background:var(--green-btn)}
.btn-add:active{transform:scale(.97)}

/* ── Tabel ── */
.table-card{background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow);padding:22px}
.table-toolbar{display:flex;flex-wrap:wrap;gap:14px;justify-content:space-between;align-items:center;margin-bottom:16px}
.search-box{display:flex;align-items:center;border:1px solid var(--border);border-radius:12px;padding:8px 12px;background:var(--bg);gap:10px;flex:1;max-width:420px}
.search-box i{color:var(--muted)}
.search-box input{border:none;outline:none;width:100%;font-size:14px;background:transparent;color:var(--text)}
.dtable{width:100%;border-collapse:collapse;font-size:14px}
.dtable th,.dtable td{padding:12px 14px;border:1px solid var(--border);text-align:left;vertical-align:middle}
.dtable th{background:var(--bg);font-weight:600;color:var(--muted)}
.dtable tr:nth-child(even){background:rgba(0,0,0,.02)}
.action-cell{display:flex;gap:6px;justify-content:flex-end}
.btn-icon{width:34px;height:34px;border-radius:10px;border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .2s,border-color .2s;color:var(--muted)}
.btn-icon:hover{border-color:var(--green-light);background:var(--green-pale);color:var(--green)}
.btn-icon.red:hover{border-color:var(--red);background:var(--red-pale);color:var(--red)}
.empty-state{padding:40px;text-align:center;color:var(--muted)}
.empty-state i{font-size:32px;margin-bottom:12px}

/* ── Modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:500;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s}
.modal-overlay.show{opacity:1;pointer-events:all}
.modal-box{background:var(--surface);border-radius:20px;padding:28px;width:420px;max-width:calc(100vw - 32px);box-shadow:0 20px 60px rgba(0,0,0,.18);transform:scale(.92) translateY(10px);transition:transform .25s;text-align:left}
.modal-overlay.show .modal-box{transform:scale(1) translateY(0)}
.modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.modal-head h3{font-size:18px;margin:0}
.btn-close-modal{width:34px;height:34px;border-radius:12px;border:1px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted)}
.fg{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.fg label{font-size:13px;color:var(--muted)}
.fi{padding:10px 12px;border-radius:10px;border:1.5px solid var(--border);font-family:inherit;font-size:14px;color:var(--text);background:var(--bg);outline:none;transition:border-color .2s,background .2s;width:100%}
.fi:focus{border-color:var(--green-light);background:#fff}
.mfooter{display:flex;gap:10px;margin-top:8px}
.mbtn{flex:1;padding:11px;border-radius:10px;border:none;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;transition:background .2s}
.mbtn.primary{background:var(--green);color:#fff}
.mbtn.primary:hover{background:var(--green-btn)}
.mbtn.secondary{background:var(--bg);color:var(--text);border:1.5px solid var(--border)}
.mbtn.secondary:hover{background:var(--border)}
.mbtn.danger{background:var(--red);color:#fff}
.mbtn.danger:hover{background:#c1121f}

/* ── Toast ── */
.toast{position:fixed;bottom:24px;right:24px;background:var(--green-btn);color:#fff;padding:12px 20px;border-radius:12px;font-size:14px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.2);display:flex;align-items:center;gap:10px;z-index:600;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s}
.toast.show{transform:translateY(0);opacity:1}
.toast.error{background:var(--red)}

::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:#c8d8c8;border-radius:4px}
</style>
</head>
<body>

<!-- TOP NAV — sama seperti dashboard.php -->
<nav class="topnav">
    <a href="../dashboard.php" class="sb-brand" style="border:none;margin:0;padding:0;text-decoration:none">
        <i class="fas fa-capsules"></i> APOTEK
    </a>
    <span style="font-size:14px;font-weight:600;color:var(--muted)">/</span>
    <span style="font-size:14px;font-weight:600;color:var(--text)">Member</span>
    <div class="topnav-right">
        <div class="icon-btn">
            <i class="fas fa-bell"></i>
        </div>
        <div class="user-info">
            <div class="user-texts">
                <div class="uname"><?= htmlspecialchars($user['nama_user']) ?></div>
                <div class="urole"><?= htmlspecialchars($user['role']) ?></div>
            </div>
            <div class="ddwrap" id="ddwrap">
                <div class="user-avatar" onclick="toggleDropdown()"><?= strtoupper(substr($user['nama_user'], 0, 1)) ?></div>
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
        <a class="sb-link" href="supplier.php"><i class="fas fa-truck"></i> Supplier</a>
        <a class="sb-link" href="obat.php"><i class="fas fa-pills"></i> Obat</a>
        <a class="sb-link active" href="member.php"><i class="fas fa-user-friends"></i> Member</a>
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
                <h2>Member</h2>
                <p>Kelola data member dan pencarian cepat</p>
            </div>
            <button class="btn-add" onclick="openModal('m-tambah')">
                <i class="fas fa-plus"></i> Tambah Member
            </button>
        </div>

        <div class="table-card">
            <form method="GET" id="ff">
                <div class="table-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="q" placeholder="Cari nama / no HP / alamat..."
                            value="<?= htmlspecialchars($search) ?>"
                            onchange="document.getElementById('ff').submit()">
                    </div>
                </div>
            </form>

            <div style="overflow-x:auto">
                <table class="dtable" id="main-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nama Lengkap</th>
                            <th>No. HP</th>
                            <th>Alamat</th>
                            <th class="center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($members)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <i class="fas fa-user"></i>
                                    <p>Tidak ada member ditemukan</p>
                                    <p style="font-size:12px;color:var(--muted)">Tambah member baru melalui tombol di atas</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: foreach ($members as $m): ?>
                        <tr id="row-<?= $m['id_member'] ?>">
                            <td>#<?= $m['id_member'] ?></td>
                            <td><?= htmlspecialchars($m['nama_lengkap']) ?></td>
                            <td><?= htmlspecialchars($m['no_hp']) ?></td>
                            <td><?= htmlspecialchars($m['alamat']) ?></td>
                            <td>
                                <div class="action-cell">
                                    <button class="btn-icon" title="Edit"
                                        onclick="openEdit(<?= $m['id_member'] ?>,'<?= addslashes(htmlspecialchars($m['nama_lengkap'])) ?>','<?= addslashes(htmlspecialchars($m['no_hp'])) ?>','<?= addslashes(htmlspecialchars($m['alamat'])) ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-icon red" title="Hapus"
                                        onclick="confirmHapus(<?= $m['id_member'] ?>,'<?= addslashes(htmlspecialchars($m['nama_lengkap'])) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
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
            <input type="text" id="t-hp" class="fi" placeholder="08xxxxxx">
        </div>
        <div class="fg">
            <label>Alamat</label>
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
let editId  = null;
let hapusId = null;

// ── Modal ──
function openModal(id)  { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) o.classList.remove('show'); });
});

// ── Toast ──
function showToast(msg, error = false) {
    const t = document.getElementById('toast');
    t.innerHTML = `<i class="fas fa-${error ? 'exclamation-circle' : 'check-circle'}"></i> ${msg}`;
    t.className = 'toast show' + (error ? ' error' : '');
    setTimeout(() => t.className = 'toast', 2800);
}

// ── Tambah ──
function submitTambah() {
    const nama   = document.getElementById('t-nama').value.trim();
    const hp     = document.getElementById('t-hp').value.trim();
    const alamat = document.getElementById('t-alamat').value.trim();
    if (!nama || !hp) { showToast('Nama dan No. HP wajib diisi!', true); return; }

    const fd = new FormData();
    fd.append('ajax_tambah', '1');
    fd.append('nama_lengkap', nama);
    fd.append('no_hp', hp);
    fd.append('alamat', alamat);

    fetch(BASE_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { closeModal('m-tambah'); showToast(d.message); insertRow(d.id, d.nama, d.hp, d.alamat); }
            else showToast(d.message, true);
        }).catch(() => showToast('Koneksi gagal.', true));
}

function insertRow(id, nama, hp, alamat) {
    const tbody = document.querySelector('#main-table tbody');
    const empty = tbody.querySelector('.empty-state');
    if (empty) tbody.innerHTML = '';
    const row = document.createElement('tr');
    row.id = 'row-' + id;
    row.innerHTML = `
        <td>#${id}</td>
        <td>${nama}</td>
        <td>${hp}</td>
        <td>${alamat || '—'}</td>
        <td><div class="action-cell">
            <button class="btn-icon" onclick="openEdit(${id},'${nama.replace(/'/g,"\\'")}','${hp.replace(/'/g,"\\'")}','${alamat.replace(/'/g,"\\'")}')"><i class="fas fa-edit"></i></button>
            <button class="btn-icon red" onclick="confirmHapus(${id},'${nama.replace(/'/g,"\\'")}')"><i class="fas fa-trash"></i></button>
        </div></td>`;
    tbody.prepend(row);
}

// ── Edit ──
function openEdit(id, nama, hp, alamat) {
    editId = id;
    document.getElementById('e-id').value    = id;
    document.getElementById('e-nama').value  = nama;
    document.getElementById('e-hp').value    = hp;
    document.getElementById('e-alamat').value= alamat;
    openModal('m-edit');
}

function submitEdit() {
    const nama   = document.getElementById('e-nama').value.trim();
    const hp     = document.getElementById('e-hp').value.trim();
    const alamat = document.getElementById('e-alamat').value.trim();
    if (!nama || !hp) { showToast('Nama dan No. HP wajib diisi!', true); return; }

    const fd = new FormData();
    fd.append('ajax_edit', '1');
    fd.append('id_member', editId);
    fd.append('nama_lengkap', nama);
    fd.append('no_hp', hp);
    fd.append('alamat', alamat);

    fetch(BASE_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                closeModal('m-edit'); showToast(d.message);
                const row = document.getElementById('row-' + editId);
                if (row) {
                    const cols = row.querySelectorAll('td');
                    cols[1].textContent = nama;
                    cols[2].textContent = hp;
                    cols[3].textContent = alamat;
                }
            } else showToast(d.message, true);
        }).catch(() => showToast('Koneksi gagal.', true));
}

// ── Hapus ──
function confirmHapus(id, nama) {
    hapusId = id;
    document.getElementById('hapus-text').textContent = `"${nama}" akan dihapus permanen.`;
    openModal('m-hapus');
}

function submitHapus() {
    const fd = new FormData();
    fd.append('ajax_hapus', '1');
    fd.append('id_member', hapusId);
    fetch(BASE_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            closeModal('m-hapus');
            if (d.success) {
                showToast(d.message);
                const row = document.getElementById('row-' + hapusId);
                if (row) row.remove();
            } else showToast(d.message, true);
        }).catch(() => showToast('Koneksi gagal.', true));
}

// ── Dropdown user — klik avatar untuk buka/tutup ──
function toggleDropdown() {
    var menu = document.getElementById('ddmenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
}
// Klik di luar = tutup otomatis
document.addEventListener('click', function(e) {
    var wrap = document.getElementById('ddwrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('ddmenu').style.display = 'none';
    }
});
</script>
</body>
</html>