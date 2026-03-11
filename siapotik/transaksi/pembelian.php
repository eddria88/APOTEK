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

// ── AJAX: Simpan Pembelian ──
if (isset($_POST['ajax_simpan'])) {
    header('Content-Type: application/json');

    $tanggal      = $_POST['tanggal'];
    $id_supplier  = $_POST['id_supplier'];
    $id_obat      = $_POST['id_obat'];
    $jumlah       = (int)   $_POST['jumlah'];
    $harga_beli   = (float) $_POST['harga_beli'];
    $batch        = $_POST['batch'];
    $expired_date = $_POST['expired_date'];
    $dibayar      = (float) $_POST['dibayar'];

    $total = $jumlah * $harga_beli;
    $sisa  = $total - $dibayar;

    if ($sisa <= 0) {
        $status = 'Lunas';
        $sisa = 0;
    } else {
        $status = 'Hutang';
    }

    $ok = mysqli_query(
        $conn,
        "INSERT INTO pembelian (tanggal,id_supplier,id_obat,jumlah,harga_beli,batch,expired_date,total,dibayar,sisa,status_pembayaran)
         VALUES ('$tanggal','$id_supplier','$id_obat','$jumlah','$harga_beli','$batch','$expired_date','$total','$dibayar','$sisa','$status')"
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

// ── Stok menipis (stok < 20) ──
$stokTipis = mysqli_query($conn, "SELECT * FROM obat WHERE stok < 20 ORDER BY stok ASC LIMIT 10");
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembelian Obat — Apotek</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --green: #2d6a4f;
            --green-mid: #40916c;
            --green-light: #52b788;
            --green-pale: #d8f3dc;
            --green-btn: #1b4332;
            --bg: #f4f6f3;
            --surface: #ffffff;
            --border: #e0e6de;
            --text: #1a2e1a;
            --muted: #6b7e6b;
            --red: #e63946;
            --red-pale: #ffeef0;
            --amber: #e07b00;
            --amber-pale: #fff3e0;
            --radius: 14px;
            --shadow: 0 2px 12px rgba(0, 0, 0, .07);
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── TOP NAV ── */
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
            box-shadow: 0 1px 6px rgba(0, 0, 0, .05);
        }

        .topnav .brand {
            font-weight: 700;
            font-size: 17px;
            color: var(--green);
            letter-spacing: -.3px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .topnav .brand i {
            color: var(--green-light);
        }

        .topnav .page-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            margin-left: 8px;
        }

        .topnav-right {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 10px;
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
            transition: border-color .2s, color .2s;
        }

        .icon-btn:hover {
            border-color: var(--green-light);
            color: var(--green);
        }

        .icon-btn .badge {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 7px;
            height: 7px;
            background: var(--red);
            border-radius: 50%;
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
            transition: border-color .2s;
        }

        .user-chip:hover {
            border-color: var(--green-light);
        }

        .user-chip .avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--green-pale);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
        }

        .user-chip .caret {
            color: var(--muted);
            font-size: 11px;
        }

        .dropdown-wrap {
            position: relative;
        }

        .dropdown-menu-custom {
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
            z-index: 200;
        }

        .dropdown-wrap:hover .dropdown-menu-custom {
            display: block;
        }

        .dropdown-menu-custom a,
        .dropdown-menu-custom span {
            display: block;
            padding: 8px 12px;
            font-size: 13px;
            color: var(--text);
            border-radius: 8px;
            text-decoration: none;
        }

        .dropdown-menu-custom a:hover {
            background: var(--green-pale);
            color: var(--green);
        }

        .dropdown-menu-custom .role-label {
            color: var(--muted);
            font-size: 12px;
        }

        .dropdown-menu-custom hr {
            border: none;
            border-top: 1px solid var(--border);
            margin: 4px 0;
        }

        .dropdown-menu-custom .logout {
            color: var(--red) !important;
        }

        .dropdown-menu-custom .logout:hover {
            background: var(--red-pale) !important;
        }

        /* ── LAYOUT ── */
        .app-body {
            display: flex;
            flex: 1;
            overflow: hidden;
            height: calc(100vh - 56px);
        }

        /* ── SIDEBAR ── */
        .sidebar {
            width: 220px;
            min-width: 220px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            overflow-y: auto;
            padding: 16px 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-section-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--muted);
            padding: 8px 20px 4px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 20px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--muted);
            text-decoration: none;
            transition: background .15s, color .15s;
        }

        .sidebar-link i {
            width: 16px;
            text-align: center;
            font-size: 13px;
        }

        .sidebar-link:hover {
            background: var(--green-pale);
            color: var(--green);
        }

        .sidebar-link.active {
            background: var(--green-pale);
            color: var(--green);
            font-weight: 700;
            border-right: 3px solid var(--green);
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            font-size: 12px;
            color: var(--muted);
        }

        .sidebar-footer strong {
            display: block;
            color: var(--text);
            font-size: 13px;
        }

        /* ── MAIN CONTENT ── */
        .main-content {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* ── PAGE HEADER ── */
        .page-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text);
        }

        .page-header p {
            font-size: 13.5px;
            color: var(--muted);
            margin-top: 3px;
        }

        /* ── FORM GRID ── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 900px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .form-card h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .text-green {
            color: var(--green-mid);
        }

        .text-red {
            color: var(--red);
        }

        .text-amber {
            color: var(--amber);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group label {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--muted);
        }

        .form-input,
        .form-select {
            padding: 9px 12px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            font-family: inherit;
            font-size: 13.5px;
            color: var(--text);
            background: var(--bg);
            outline: none;
            transition: border-color .2s, background .2s;
            width: 100%;
        }

        .form-input:focus,
        .form-select:focus {
            border-color: var(--green-light);
            background: #fff;
        }

        .form-input::placeholder {
            color: #aab8aa;
        }

        .total-preview {
            background: var(--green-pale);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .total-preview span:last-child {
            font-weight: 700;
            font-size: 16px;
            color: var(--green);
        }

        .sisa-preview {
            background: var(--amber-pale);
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 12.5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sisa-preview span:last-child {
            font-weight: 700;
            color: var(--amber);
        }

        .btn-full {
            width: 100%;
            padding: 12px;
            border-radius: 12px;
            border: none;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background .2s, transform .1s;
        }

        .btn-full:active {
            transform: scale(.98);
        }

        .btn-primary {
            background: var(--green);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--green-btn);
        }

        /* ── ALERT BOX (stok menipis) ── */
        .alert-box {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px 24px;
            border-left: 5px solid var(--amber);
        }

        .alert-box h3 {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--amber);
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }

        .alert-list {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .alert-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 11px 4px;
            border-bottom: 1px solid var(--border);
            font-size: 13.5px;
        }

        .alert-item:last-child {
            border-bottom: none;
        }

        .alert-badge {
            background: var(--amber-pale);
            color: var(--amber);
            border-radius: 20px;
            padding: 3px 12px;
            font-size: 12px;
            font-weight: 700;
        }

        .alert-badge.critical {
            background: var(--red-pale);
            color: var(--red);
        }

        /* ── HISTORY TABLE ── */
        .history-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .history-card-header {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
        }

        .history-card-header i {
            color: var(--green);
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .data-table thead th {
            background: var(--bg);
            padding: 10px 14px;
            text-align: left;
            font-weight: 700;
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            border-bottom: 1px solid var(--border);
        }

        .data-table tbody td {
            padding: 11px 14px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover td {
            background: var(--bg);
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
        }

        .badge-lunas {
            background: var(--green-pale);
            color: var(--green);
        }

        .badge-hutang {
            background: var(--red-pale);
            color: var(--red);
        }

        .btn-bayar {
            padding: 5px 12px;
            border-radius: 8px;
            border: 1.5px solid var(--green-light);
            background: var(--surface);
            color: var(--green);
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s, color .2s;
        }

        .btn-bayar:hover {
            background: var(--green);
            color: #fff;
        }

        .sisa-amount {
            font-weight: 700;
            color: var(--red);
        }

        /* ── MODAL ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .35);
            z-index: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s;
        }

        .modal-overlay.show {
            opacity: 1;
            pointer-events: all;
        }

        .modal-box {
            background: var(--surface);
            border-radius: 20px;
            padding: 28px;
            width: 380px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
            transform: scale(.9);
            transition: transform .25s;
        }

        .modal-overlay.show .modal-box {
            transform: scale(1);
        }

        .modal-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin: 0 auto 14px;
        }

        .modal-icon.green {
            background: var(--green-pale);
            color: var(--green);
        }

        .modal-icon.amber {
            background: var(--amber-pale);
            color: var(--amber);
        }

        .modal-icon.red {
            background: var(--red-pale);
            color: var(--red);
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 6px;
        }

        .modal-subtitle {
            font-size: 13px;
            color: var(--muted);
            text-align: center;
            margin-bottom: 18px;
        }

        .modal-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 12px;
        }

        .modal-field label {
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
        }

        .modal-field input {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            font-family: inherit;
            font-size: 14px;
            color: var(--text);
            background: var(--bg);
            outline: none;
            transition: border-color .2s;
        }

        .modal-field input:focus {
            border-color: var(--green-light);
            background: #fff;
        }

        .modal-info-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            padding: 6px 0;
            border-bottom: 1px solid var(--border);
        }

        .modal-info-row:last-of-type {
            border-bottom: none;
        }

        .modal-info-row span:last-child {
            font-weight: 700;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            margin-top: 18px;
        }

        .modal-btn {
            flex: 1;
            padding: 11px;
            border-radius: 10px;
            border: none;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s;
        }

        .modal-btn.primary {
            background: var(--green);
            color: #fff;
        }

        .modal-btn.primary:hover {
            background: var(--green-btn);
        }

        .modal-btn.secondary {
            background: var(--bg);
            color: var(--text);
            border: 1.5px solid var(--border);
        }

        .modal-btn.secondary:hover {
            background: var(--border);
        }

        /* ── TOAST ── */
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
            transition: transform .3s, opacity .3s;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.error {
            background: var(--red);
        }

        ::-webkit-scrollbar {
            width: 5px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #c8d8c8;
            border-radius: 4px;
        }
    </style>
</head>

<body>

    <!-- TOP NAV -->
    <nav class="topnav">
        <a href="#" class="brand"><i class="fas fa-capsules"></i> APOTEK</a>
        <span class="page-title">/ Pembelian Obat</span>
        <div class="topnav-right">
            <div class="icon-btn"><i class="fas fa-bell"></i><span class="badge"></span></div>
            <div class="icon-btn"><i class="fas fa-cog"></i></div>
            <div class="dropdown-wrap">
                <div class="user-chip">
                    <div class="avatar"><?= strtoupper(substr($user['nama_user'], 0, 2)) ?></div>
                    <?= htmlspecialchars($user['nama_user']) ?>
                    <i class="fas fa-chevron-down caret"></i>
                </div>
                <div class="dropdown-menu-custom">
                    <span class="role-label">Role: <?= htmlspecialchars($user['role']) ?></span>
                    <hr>
                    <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="app-body">

        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-section-title">Core</div>
            <a class="sidebar-link" href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>

            <div class="sidebar-section-title">Master Data</div>
            <a class="sidebar-link" href="../master/kategori.php"><i class="fas fa-tags"></i> Kategori</a>
            <a class="sidebar-link" href="../master/supplier.php"><i class="fas fa-truck"></i> Supplier</a>
            <a class="sidebar-link" href="../master/obat.php"><i class="fas fa-pills"></i> Obat</a>

            <div class="sidebar-section-title">Transaksi</div>
            <a class="sidebar-link active" href="pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
            <a class="sidebar-link" href="penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>

            <div class="sidebar-section-title">Laporan</div>
            <a class="sidebar-link" href="../laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
            <a class="sidebar-link" href="../laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
            <a class="sidebar-link" href="../laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>

            <div class="sidebar-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="main-content">

            <!-- PAGE HEADER -->
            <div class="page-header">
                <h2>Pembelian Obat</h2>
                <p>Catat pembelian stok dari supplier, kelola hutang, dan pantau stok menipis</p>
            </div>

            <!-- FORM GRID -->
            <div class="form-grid">

                <!-- Stok Masuk / Form Pembelian -->
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
                        <select id="f-obat" class="form-select">
                            <option value="">-- Pilih Obat --</option>
                            <?php while ($o = mysqli_fetch_assoc($obatResult)): ?>
                                <option value="<?= $o['id_obat'] ?>"><?= htmlspecialchars($o['nama_obat']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Batch / No. Lot</label>
                            <input type="text" id="f-batch" class="form-input" placeholder="Cth: BTH-2026-01">
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
                        <input type="number" id="f-dibayar" class="form-input" placeholder="0" min="0" oninput="calcSisa()">
                    </div>

                    <div class="sisa-preview" id="sisa-preview" style="display:none">
                        <span>Sisa Hutang</span>
                        <span id="preview-sisa">Rp 0</span>
                    </div>

                    <button class="btn-full btn-primary" onclick="submitPembelian()">
                        <i class="fas fa-save"></i> Simpan Pembelian
                    </button>
                </div>

                <!-- Right column: Stok Menipis + Info -->
                <div style="display:flex;flex-direction:column;gap:20px;">

                    <!-- Hutang summary -->
                    <div class="form-card" style="background: linear-gradient(135deg,#fff8f0,#fff)">
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

                </div>
            </div>

            <!-- HISTORY TABLE -->
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
                                <th>Aksi</th>
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
                                    <td>
                                        <?php if ($isHutang): ?>
                                            <button class="btn-bayar" onclick="openBayarModal(<?= $row['id_pembelian'] ?>, '<?= addslashes($row['nama_obat']) ?>', <?= number_format((float)$row['sisa'], 2, '.', '') ?>)">
                                                <i class="fas fa-money-bill-wave"></i> Bayar
                                            </button>
                                        <?php else: ?>
                                            <span style="color:var(--muted);font-size:12px">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

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
                <input type="number" id="input-bayar" placeholder="Masukkan nominal..." min="1" oninput="previewBayar()">
            </div>

            <div class="sisa-preview" id="modal-sisa-preview" style="display:none;margin-top:4px">
                <span>Sisa setelah bayar</span>
                <span id="modal-sisa-after">Rp 0</span>
            </div>

            <div class="modal-footer">
                <button class="modal-btn secondary" onclick="closeModal('modal-bayar')">Batal</button>
                <button class="modal-btn primary" onclick="submitBayar()">
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
        // ── Calc helpers ──
        function formatRp(n) {
            return 'Rp ' + Number(n).toLocaleString('id-ID');
        }

        function calcTotal() {
            const j = parseFloat(document.getElementById('f-jumlah').value) || 0;
            const h = parseFloat(document.getElementById('f-harga').value) || 0;
            document.getElementById('preview-total').textContent = formatRp(j * h);
            calcSisa();
        }

        function calcSisa() {
            const j = parseFloat(document.getElementById('f-jumlah').value) || 0;
            const h = parseFloat(document.getElementById('f-harga').value) || 0;
            const d = parseFloat(document.getElementById('f-dibayar').value) || 0;
            const total = j * h;
            const sisa = total - d;
            const previewEl = document.getElementById('sisa-preview');
            if (d > 0 && sisa > 0) {
                previewEl.style.display = 'flex';
                document.getElementById('preview-sisa').textContent = formatRp(sisa);
            } else {
                previewEl.style.display = 'none';
            }
        }

        // ── Submit Pembelian ──
        function submitPembelian() {
            const tanggal = document.getElementById('f-tanggal').value;
            const id_supplier = document.getElementById('f-supplier').value;
            const id_obat = document.getElementById('f-obat').value;
            const batch = document.getElementById('f-batch').value.trim();
            const expired = document.getElementById('f-expired').value;
            const jumlah = document.getElementById('f-jumlah').value;
            const harga = document.getElementById('f-harga').value;
            const dibayar = document.getElementById('f-dibayar').value || 0;

            if (!tanggal || !id_supplier || !id_obat || !batch || !expired || !jumlah || !harga) {
                showToast('Harap isi semua field yang diperlukan!', true);
                return;
            }

            const fd = new FormData();
            fd.append('ajax_simpan', '1');
            fd.append('tanggal', tanggal);
            fd.append('id_supplier', id_supplier);
            fd.append('id_obat', id_obat);
            fd.append('batch', batch);
            fd.append('expired_date', expired);
            fd.append('jumlah', jumlah);
            fd.append('harga_beli', harga);
            fd.append('dibayar', dibayar);

            fetch(window.location.href, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modal-sukses-text').textContent = data.message;
                        openModal('modal-sukses');
                        setTimeout(() => {
                            closeModal('modal-sukses');
                            location.reload();
                        }, 1800);
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
        let activeBayarId = null;
        let activeBayarSisa = 0;

        function openBayarModal(id, nama, sisa) {
            activeBayarId = id;
            activeBayarSisa = sisa;
            document.getElementById('modal-nama-obat').textContent = nama;
            document.getElementById('modal-sisa-text').textContent = formatRp(sisa);
            document.getElementById('input-bayar').value = '';
            document.getElementById('modal-sisa-preview').style.display = 'none';
            openModal('modal-bayar');
        }

        function previewBayar() {
            const bayar = parseFloat(document.getElementById('input-bayar').value) || 0;
            const sisa_after = activeBayarSisa - bayar;
            const el = document.getElementById('modal-sisa-preview');
            if (bayar > 0) {
                el.style.display = 'flex';
                const afterEl = document.getElementById('modal-sisa-after');
                afterEl.textContent = sisa_after <= 0 ? '✓ Lunas' : formatRp(sisa_after);
                afterEl.style.color = sisa_after <= 0 ? 'var(--green)' : 'var(--amber)';
            } else {
                el.style.display = 'none';
            }
        }

        function submitBayar() {
            const bayar = parseFloat(document.getElementById('input-bayar').value) || 0;
            if (!bayar || bayar <= 0) {
                showToast('Masukkan jumlah pembayaran!', true);
                return;
            }

            const fd = new FormData();
            fd.append('ajax_bayar', '1');
            fd.append('id_pembelian', activeBayarId);
            fd.append('bayar_tambah', bayar);

            fetch(window.location.href, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        closeModal('modal-bayar');
                        // Update row in table
                        const sisaCell = document.querySelector(`.sisa-cell-${activeBayarId}`);
                        const statusCell = document.querySelector(`.status-cell-${activeBayarId}`);
                        if (sisaCell) sisaCell.textContent = formatRp(data.sisa_baru);
                        if (statusCell) {
                            statusCell.textContent = data.status === 'Lunas' ? '✓ Lunas' : '⚠ Hutang';
                            statusCell.className = `badge-status badge-${data.status.toLowerCase()} status-cell-${activeBayarId}`;
                        }
                        if (data.status === 'Lunas') {
                            const btn = document.querySelector(`#row-${activeBayarId} .btn-bayar`);
                            if (btn) btn.parentElement.innerHTML = '<span style="color:var(--muted);font-size:12px">—</span>';
                        }
                        showToast('Pembayaran berhasil dicatat!');
                    } else {
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
        function openModal(id) {
            document.getElementById(id).classList.add('show');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        // ── Toast ──
        function showToast(msg, error = false) {
            const t = document.getElementById('toast');
            t.innerHTML = `<i class="fas fa-${error ? 'exclamation-circle' : 'check-circle'}"></i> ${msg}`;
            t.className = 'toast show' + (error ? ' error' : '');
            setTimeout(() => t.className = 'toast', 2800);
        }
    </script>

</body>

</html>