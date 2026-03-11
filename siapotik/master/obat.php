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
    $nama         = mysqli_real_escape_string($conn, $_POST['nama_obat']);
    $id_kategori  = (int)   $_POST['id_kategori'];
    $satuan       = mysqli_real_escape_string($conn, $_POST['satuan']);
    $harga_jual   = (float) $_POST['harga_jual'];
    $stok         = (int)   $_POST['stok'];
    $stok_minimum = (int)   $_POST['stok_minimum'];

    $gambar = '';
    $up = handleUploadGambar('gambar', $uploadDir);
    if (is_array($up)) {
        echo json_encode(['success' => false, 'message' => $up['error']]);
        exit;
    }
    if ($up) $gambar = $up;

    $ok = mysqli_query(
        $conn,
        "INSERT INTO obat (nama_obat,id_kategori,satuan,harga_jual,stok,stok_minimum,gambar)
         VALUES ('$nama','$id_kategori','$satuan','$harga_jual','$stok','$stok_minimum','$gambar')"
    );

    echo json_encode($ok
        ? ['success' => true,  'message' => 'Obat berhasil ditambahkan!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Edit
if (isset($_POST['ajax_edit'])) {
    header('Content-Type: application/json');
    $id           = (int)   $_POST['id_obat'];
    $nama         = mysqli_real_escape_string($conn, $_POST['nama_obat']);
    $id_kategori  = (int)   $_POST['id_kategori'];
    $satuan       = mysqli_real_escape_string($conn, $_POST['satuan']);
    $harga_jual   = (float) $_POST['harga_jual'];
    $stok         = (int)   $_POST['stok'];
    $stok_minimum = (int)   $_POST['stok_minimum'];

    $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT gambar FROM obat WHERE id_obat='$id'"));
    $gambar   = $existing['gambar'] ?? '';

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

    echo json_encode($ok
        ? ['success' => true,  'message' => 'Data obat berhasil diperbarui!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Hapus
if (isset($_POST['ajax_hapus'])) {
    header('Content-Type: application/json');
    $id  = (int) $_POST['id_obat'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT gambar FROM obat WHERE id_obat='$id'"));
    $ok  = mysqli_query($conn, "DELETE FROM obat WHERE id_obat='$id'");
    if ($ok && !empty($row['gambar']) && file_exists($uploadDir . $row['gambar'])) unlink($uploadDir . $row['gambar']);
    echo json_encode($ok
        ? ['success' => true,  'message' => 'Obat berhasil dihapus!']
        : ['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
}

// AJAX: Get single (for edit)
if (isset($_GET['get_obat'])) {
    header('Content-Type: application/json');
    $id  = (int) $_GET['get_obat'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM obat WHERE id_obat='$id'"));
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
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
            --blue-pale: #e8f4fd;
            --amber: #e07b00;
            --amber-pale: #fff3e0;
            --purple: #7c3aed;
            --purple-pale: #f3eeff;
            --radius: 14px;
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

        /* NAV */
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

        .dropdown-wrap {
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

        .dropdown-wrap:hover .ddmenu {
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

        /* LAYOUT */
        .app-body {
            display: flex;
            flex: 1;
            overflow: hidden;
            height: calc(100vh - 56px)
        }

        /* SIDEBAR */
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

        /* MAIN */
        .main-content {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 20px
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

        /* TABLE CARD */
        .table-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden
        }

        .table-toolbar {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 9px 14px;
            flex: 1;
            min-width: 220px;
            transition: border-color .2s
        }

        .search-box:focus-within {
            border-color: var(--green-light);
            background: #fff
        }

        .search-box i {
            color: var(--muted);
            font-size: 13px
        }

        .search-box input {
            flex: 1;
            border: none;
            background: none;
            font-family: inherit;
            font-size: 13.5px;
            color: var(--text);
            outline: none
        }

        .search-box input::placeholder {
            color: #aab8aa
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: auto
        }

        .select-sm {
            padding: 8px 12px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            font-family: inherit;
            font-size: 13px;
            color: var(--text);
            background: var(--bg);
            outline: none;
            cursor: pointer;
            transition: border-color .2s
        }

        .select-sm:focus {
            border-color: var(--green-light)
        }

        .btn-export {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: all .2s
        }

        .btn-export:hover {
            border-color: var(--green-light);
            color: var(--green);
            background: var(--green-pale)
        }

        /* TABLE */
        table.dtable {
            width: 100%;
            border-collapse: collapse
        }

        .dtable thead th {
            background: var(--bg);
            padding: 11px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .6px;
            border-bottom: 1px solid var(--border)
        }

        .dtable thead th.center {
            text-align: center
        }

        .dtable tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            font-size: 13.5px;
            vertical-align: middle
        }

        .dtable tbody tr:last-child td {
            border-bottom: none
        }

        .dtable tbody tr:hover td {
            background: #fafcfa
        }

        .id-mono {
            font-family: 'DM Mono', monospace;
            font-size: 12px;
            color: var(--muted)
        }

        /* Product cell */
        .product-info {
            display: flex;
            align-items: center;
            gap: 10px
        }

        .product-thumb {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            object-fit: cover;
            border: 1.5px solid var(--border);
            flex-shrink: 0
        }

        .product-thumb-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            border: 1.5px solid var(--border)
        }

        /* Badges */
        .badge-kat {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700
        }

        .badge-bebas {
            background: var(--blue-pale);
            color: var(--blue)
        }

        .badge-keras {
            background: var(--red-pale);
            color: var(--red)
        }

        .badge-vitamin {
            background: var(--amber-pale);
            color: var(--amber)
        }

        .badge-herbal {
            background: var(--green-pale);
            color: var(--green)
        }

        .badge-other {
            background: var(--purple-pale);
            color: var(--purple)
        }

        .stok-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700
        }

        .stok-aman {
            background: var(--green-pale);
            color: var(--green)
        }

        .stok-low {
            background: var(--red-pale);
            color: var(--red)
        }

        .stok-medium {
            background: var(--amber-pale);
            color: var(--amber)
        }

        .price-sell {
            font-weight: 700;
            font-size: 14px
        }

        /* Action btns */
        .btn-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            cursor: pointer;
            color: var(--muted);
            transition: all .15s
        }

        .btn-icon.blue:hover {
            background: var(--blue-pale);
            border-color: var(--blue);
            color: var(--blue)
        }

        .btn-icon.red:hover {
            background: var(--red-pale);
            border-color: var(--red);
            color: var(--red)
        }

        .action-cell {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px
        }

        /* Pagination */
        .table-footer {
            padding: 14px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px
        }

        .table-footer p {
            font-size: 12.5px;
            color: var(--muted)
        }

        .pagination {
            display: flex;
            gap: 6px
        }

        .btn-page {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: all .15s
        }

        .btn-page:hover:not(:disabled) {
            border-color: var(--green-light);
            color: var(--green);
            background: var(--green-pale)
        }

        .btn-page.active {
            background: var(--green);
            border-color: var(--green);
            color: #fff
        }

        .btn-page:disabled {
            opacity: .4;
            cursor: not-allowed
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
            width: 500px;
            max-width: calc(100vw - 32px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, .18);
            transform: scale(.92) translateY(10px);
            transition: transform .25s;
            max-height: calc(100vh - 60px);
            overflow-y: auto
        }

        .modal-overlay.show .modal-box {
            transform: scale(1) translateY(0)
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px
        }

        .modal-head h3 {
            font-size: 17px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .modal-head h3 i {
            color: var(--green)
        }

        .btn-close-modal {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            cursor: pointer;
            color: var(--muted);
            transition: all .15s
        }

        .btn-close-modal:hover {
            background: var(--red-pale);
            border-color: var(--red);
            color: var(--red)
        }

        /* Form grid */
        .fg2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px
        }

        .fg {
            display: flex;
            flex-direction: column;
            gap: 5px
        }

        .fg.full {
            grid-column: 1/-1
        }

        .fg label {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--muted)
        }

        .fi,
        .fs {
            padding: 9px 12px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            font-family: inherit;
            font-size: 13.5px;
            color: var(--text);
            background: var(--bg);
            outline: none;
            transition: border-color .2s, background .2s;
            width: 100%
        }

        .fi:focus,
        .fs:focus {
            border-color: var(--green-light);
            background: #fff
        }

        .fi::placeholder {
            color: #aab8aa
        }

        /* IMAGE UPLOAD */
        .img-upload-area {
            border: 2px dashed var(--border);
            border-radius: 12px;
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            position: relative;
            background: var(--bg);
            min-height: 100px;
            justify-content: center
        }

        .img-upload-area:hover {
            border-color: var(--green-light);
            background: #f0faf4
        }

        .img-upload-area.has-img {
            padding: 8px
        }

        .img-upload-area input[type=file] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
            z-index: 1
        }

        .img-preview {
            width: 100%;
            max-height: 150px;
            object-fit: contain;
            border-radius: 8px;
            display: none
        }

        .img-preview.show {
            display: block
        }

        .img-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            color: var(--muted);
            pointer-events: none;
            text-align: center
        }

        .img-placeholder i {
            font-size: 30px;
            color: #c8d8c8
        }

        .img-placeholder span {
            font-size: 13px;
            font-weight: 600
        }

        .img-placeholder small {
            font-size: 11px;
            color: #aab8aa
        }

        .img-placeholder.hidden {
            display: none
        }

        .btn-rm-img {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 26px;
            height: 26px;
            border-radius: 6px;
            background: var(--red);
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 12px;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3;
            transition: background .15s
        }

        .btn-rm-img:hover {
            background: #c1121f
        }

        .btn-rm-img.show {
            display: flex
        }

        .mfooter {
            display: flex;
            gap: 10px;
            margin-top: 20px
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

        /* TOAST */
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

        .empty-state {
            text-align: center;
            padding: 48px;
            color: var(--muted)
        }

        .empty-state i {
            font-size: 36px;
            color: #d0ddd0;
            display: block;
            margin-bottom: 10px
        }
    </style>
</head>

<body>

    <nav class="topnav">
        <a href="#" class="brand"><i class="fas fa-capsules"></i> APOTEK</a>
        <span class="page-title">/ Data Obat</span>
        <div class="topnav-right">
            <div class="icon-btn"><i class="fas fa-bell"></i><span class="badge-dot"></span></div>
            <div class="icon-btn"><i class="fas fa-cog"></i></div>
            <div class="dropdown-wrap">
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
            <a class="sb-link" href="kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <a class="sb-link" href="supplier.php"><i class="fas fa-truck"></i> Supplier</a>
            <a class="sb-link active" href="obat.php"><i class="fas fa-pills"></i> Obat</a>
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
        }

        // ── TAMBAH ──
        function submitTambah() {
            const nama = document.getElementById('t-nama').value.trim();
            const katId = document.getElementById('t-kat').value;
            const harga = document.getElementById('t-harga').value;
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
    </script>
</body>

</html>