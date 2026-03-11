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

// Handle AJAX transaction
if (isset($_POST['ajax_transaksi'])) {
    header('Content-Type: application/json');

    $tanggal     = date("Y-m-d H:i:s");
    $items       = json_decode($_POST['items'], true);
    $bayar       = (float) $_POST['bayar'];
    $metode      = $_POST['metode'];
    $total       = (float) $_POST['total'];
    $kembalian   = $bayar - $total;

    if ($kembalian < 0 && $metode === 'cash') {
        echo json_encode(['success' => false, 'message' => 'Uang bayar kurang!']);
        exit;
    }

    mysqli_query($conn, "INSERT INTO penjualan (tanggal, total, bayar, kembalian, id_user)
        VALUES ('$tanggal','$total','$bayar','$kembalian','{$user['id_user']}')");

    $id_penjualan = mysqli_insert_id($conn);

    foreach ($items as $item) {
        $id_obat  = $item['id_obat'];
        $jumlah   = (int) $item['jumlah'];
        $harga    = (float) $item['harga'];
        $subtotal = $jumlah * $harga;

        // Check stock
        $cek = mysqli_fetch_assoc(mysqli_query($conn, "SELECT stok FROM obat WHERE id_obat='$id_obat'"));
        if ($cek['stok'] < $jumlah) {
            echo json_encode(['success' => false, 'message' => "Stok {$item['nama']} tidak cukup!"]);
            exit;
        }

        mysqli_query($conn, "INSERT INTO detail_penjualan (id_penjualan, id_obat, jumlah, harga_jual, subtotal)
            VALUES ('$id_penjualan','$id_obat','$jumlah','$harga','$subtotal')");
        mysqli_query($conn, "UPDATE obat SET stok = stok - $jumlah WHERE id_obat='$id_obat'");
        mysqli_query($conn, "INSERT INTO stok_keluar (id_obat, tanggal, jumlah, keterangan)
            VALUES ('$id_obat', NOW(), '$jumlah', 'Penjualan ID $id_penjualan')");
    }

    echo json_encode(['success' => true, 'id_penjualan' => $id_penjualan, 'kembalian' => $kembalian]);
    exit;
}

// Fetch all medicines
$obatResult = mysqli_query($conn, "SELECT o.*, k.nama_kategori FROM obat o LEFT JOIN kategori k ON o.id_kategori = k.id_kategori WHERE o.stok > 0 ORDER BY o.nama_obat ASC");
$obatList = [];
while ($row = mysqli_fetch_assoc($obatResult)) {
    $obatList[] = $row;
}

// Fetch sales history
$historyResult = mysqli_query($conn, "SELECT p.*, u.nama_user FROM penjualan p LEFT JOIN users u ON p.id_user = u.id_user ORDER BY p.id_penjualan DESC LIMIT 20");
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasir / Transaksi — Apotek</title>
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
            --amber: #f4a261;
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
            background: #ffeef0 !important;
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
            border-radius: 0;
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

        /* ── MAIN ── */
        .main-content {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        /* ── TABS ── */
        .tabs-bar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0 24px;
            display: flex;
            gap: 0;
        }

        .tab-btn {
            padding: 14px 20px;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--muted);
            border: none;
            background: none;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: color .2s, border-color .2s;
        }

        .tab-btn.active {
            color: var(--green);
            border-bottom-color: var(--green);
        }

        .tab-btn:hover {
            color: var(--green);
        }

        /* ── POS VIEW ── */
        .pos-view {
            display: flex;
            flex: 1;
            overflow: hidden;
            height: 100%;
        }

        /* Product panel */
        .pos-products {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .search-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px;
            transition: border-color .2s;
        }

        .search-box:focus-within {
            border-color: var(--green-light);
            background: #fff;
        }

        .search-box i {
            color: var(--muted);
            font-size: 15px;
        }

        .search-box input {
            flex: 1;
            border: none;
            background: none;
            font-family: inherit;
            font-size: 14px;
            color: var(--text);
            outline: none;
        }

        .search-box input::placeholder {
            color: #aab8aa;
        }

        .category-filter {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-filter {
            padding: 7px 16px;
            border-radius: 30px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: all .2s;
        }

        .btn-filter:hover {
            border-color: var(--green-light);
            color: var(--green);
        }

        .btn-filter.active {
            background: var(--green);
            border-color: var(--green);
            color: #fff;
        }

        /* Product grid */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 14px;
        }

        .product-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 18px 14px 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color .2s, transform .15s, box-shadow .2s;
            user-select: none;
        }

        .product-card:hover {
            border-color: var(--green-light);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(64, 145, 108, .15);
        }

        .product-card:active {
            transform: scale(.97);
        }

        .product-card.out-of-stock {
            opacity: .5;
            pointer-events: none;
        }

        .product-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .product-icon.green {
            background: #d8f3dc;
        }

        .product-icon.red {
            background: #fde8ea;
        }

        .product-icon.amber {
            background: #fff3e0;
        }

        .product-icon.blue {
            background: #e3f2fd;
        }

        .product-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
            text-align: center;
            line-height: 1.3;
        }

        .product-price {
            font-size: 15px;
            font-weight: 700;
            color: var(--green);
        }

        .product-stock {
            font-size: 11.5px;
            color: var(--muted);
        }

        /* Cart panel */
        .pos-cart {
            width: 320px;
            min-width: 300px;
            background: var(--surface);
            border-left: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .cart-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
        }

        .cart-header i {
            color: var(--green);
        }

        .cart-items {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .empty-cart {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #c0cfc0;
            font-size: 13px;
            padding: 40px 0;
        }

        .empty-cart i {
            font-size: 38px;
            color: #d8e8d8;
        }

        .cart-item {
            background: var(--bg);
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn .2s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(10px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .cart-item-info {
            flex: 1;
        }

        .cart-item-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
        }

        .cart-item-price {
            font-size: 12px;
            color: var(--muted);
        }

        .cart-item-subtotal {
            font-size: 13px;
            font-weight: 700;
            color: var(--green);
        }

        .qty-ctrl {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .qty-btn {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            font-weight: 700;
            font-size: 14px;
            line-height: 1;
            cursor: pointer;
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .15s, border-color .15s;
        }

        .qty-btn:hover {
            background: var(--green-pale);
            border-color: var(--green-light);
            color: var(--green);
        }

        .qty-btn.remove {
            border-color: #ffd6da;
            color: var(--red);
        }

        .qty-btn.remove:hover {
            background: #ffeef0;
            border-color: var(--red);
        }

        .qty-num {
            width: 24px;
            text-align: center;
            font-size: 13.5px;
            font-weight: 700;
        }

        .cart-footer {
            padding: 14px 16px;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--muted);
        }

        .summary-row.total {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            padding-top: 6px;
            border-top: 1.5px dashed var(--border);
            margin-top: 2px;
        }

        .payment-label {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 4px;
            display: block;
        }

        .select-input {
            width: 100%;
            padding: 9px 12px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            font-family: inherit;
            font-size: 13.5px;
            color: var(--text);
            background: var(--bg);
            outline: none;
            cursor: pointer;
            transition: border-color .2s;
        }

        .select-input:focus {
            border-color: var(--green-light);
            background: #fff;
        }

        .cash-input-wrap {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .cash-input {
            width: 100%;
            padding: 9px 12px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            font-family: inherit;
            font-size: 13.5px;
            color: var(--text);
            background: var(--bg);
            outline: none;
            transition: border-color .2s;
        }

        .cash-input:focus {
            border-color: var(--green-light);
            background: #fff;
        }

        .kembalian-row {
            display: flex;
            justify-content: space-between;
            font-size: 12.5px;
            color: var(--green-mid);
            font-weight: 600;
        }

        #member-select {
            display: none;
        }

        .btn-pay {
            width: 100%;
            padding: 13px;
            border-radius: 12px;
            border: none;
            background: var(--green);
            color: #fff;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background .2s, transform .1s;
        }

        .btn-pay:hover {
            background: var(--green-btn);
        }

        .btn-pay:active {
            transform: scale(.98);
        }

        .btn-pay:disabled {
            background: #b0c9b4;
            cursor: not-allowed;
        }

        /* ── HISTORY VIEW ── */
        .history-view {
            padding: 24px;
            display: none;
            flex-direction: column;
            gap: 20px;
        }

        .history-view.active {
            display: flex;
        }

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
            font-size: 13.5px;
        }

        .data-table thead th {
            background: var(--bg);
            padding: 10px 16px;
            text-align: left;
            font-weight: 700;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .5px;
            border-bottom: 1px solid var(--border);
        }

        .data-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover td {
            background: var(--bg);
        }

        .badge-method {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            background: var(--green-pale);
            color: var(--green);
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
            padding: 32px;
            width: 360px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
            transform: scale(.9);
            transition: transform .25s;
        }

        .modal-overlay.show .modal-box {
            transform: scale(1);
        }

        .modal-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--green-pale);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 16px;
        }

        .modal-icon.error {
            background: #ffeef0;
            color: var(--red);
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .modal-body {
            font-size: 14px;
            color: var(--muted);
            line-height: 1.6;
        }

        .modal-body strong {
            color: var(--text);
        }

        .modal-footer {
            margin-top: 24px;
            display: flex;
            gap: 10px;
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

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #c8d8c8;
            border-radius: 4px;
        }

        .hidden {
            display: none !important;
        }
    </style>
</head>

<body>

    <!-- TOP NAV -->
    <nav class="topnav">
        <a href="#" class="brand"><i class="fas fa-capsules"></i> APOTEK</a>
        <span class="page-title">/ Kasir &amp; Transaksi</span>
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
            <a class="sidebar-link" href="pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
            <a class="sidebar-link active" href="penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>

            <div class="sidebar-section-title">Laporan</div>
            <a class="sidebar-link" href="../laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
            <a class="sidebar-link" href="../laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
            <a class="sidebar-link" href="../laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>

            <div class="sidebar-footer">
                <div class="small">Masuk sebagai</div>
                <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <div class="main-content">

            <!-- TABS -->
            <div class="tabs-bar">
                <button class="tab-btn active" onclick="switchTab('pos', this)">
                    <i class="fas fa-cash-register"></i> Kasir / Transaksi
                </button>
                <button class="tab-btn" onclick="switchTab('history', this)">
                    <i class="fas fa-history"></i> Riwayat Penjualan
                </button>
            </div>

            <!-- POS VIEW -->
            <div id="tab-pos" class="pos-view">

                <!-- Products -->
                <div class="pos-products">
                    <div class="search-card">
                        <div class="search-box">
                            <i class="fas fa-barcode"></i>
                            <input type="text" id="search-product" placeholder="Scan barcode atau ketik nama obat..." oninput="filterProducts(this.value)">
                        </div>
                        <div class="category-filter">
                            <button class="btn-filter active" onclick="filterCategory(this, 'Semua')">Semua</button>
                            <button class="btn-filter" onclick="filterCategory(this, 'Obat Bebas')">Obat Bebas</button>
                            <button class="btn-filter" onclick="filterCategory(this, 'Obat Keras')">Obat Keras</button>
                            <button class="btn-filter" onclick="filterCategory(this, 'Vitamin')">Vitamin</button>
                            <button class="btn-filter" onclick="filterCategory(this, 'Herbal')">Herbal</button>
                        </div>
                    </div>
                    <div class="product-grid" id="product-grid"></div>
                </div>

                <!-- Cart -->
                <div class="pos-cart">
                    <div class="cart-header"><i class="fas fa-shopping-cart"></i> Keranjang</div>
                    <div class="cart-items" id="cart-items">
                        <div class="empty-cart" id="empty-cart">
                            <i class="fas fa-cart-plus"></i>
                            <p>Keranjang kosong</p>
                            <p style="font-size:11px;color:#c0cfc0">Pilih produk di kiri</p>
                        </div>
                    </div>
                    <div class="cart-footer">
                        <div class="summary-row"><span>Subtotal</span><span id="subtotal-display">Rp 0</span></div>
                        <div class="summary-row"><span>Diskon</span><span>Rp 0</span></div>
                        <div class="summary-row total"><span>Total</span><span id="total-display">Rp 0</span></div>

                        <div>
                            <span class="payment-label">Metode Pembayaran</span>
                            <select id="payment-method" class="select-input" onchange="togglePaymentFields(this.value)">
                                <option value="cash">💵 Tunai</option>
                                <option value="transfer">🏦 Transfer Bank</option>
                                <option value="ewallet">📱 E-Wallet</option>
                                <option value="bpjs">🏥 Member / BPJS</option>
                            </select>
                        </div>

                        <div id="cash-section" class="cash-input-wrap">
                            <span class="payment-label">Uang Bayar</span>
                            <input type="number" id="cash-input" class="cash-input" placeholder="Masukkan nominal..." oninput="calcKembalian()">
                            <div class="kembalian-row hidden" id="kembalian-row">
                                <span>Kembalian</span>
                                <span id="kembalian-display">Rp 0</span>
                            </div>
                        </div>

                        <div id="member-select" class="hidden">
                            <span class="payment-label">Pilih Member</span>
                            <select class="select-input">
                                <option>Ahmad Fauzi — BPJS</option>
                                <option>Siti Aminah — Member</option>
                                <option>Budi Santoso — BPJS</option>
                            </select>
                        </div>

                        <button class="btn-pay" id="btn-pay" onclick="confirmTransaction()" disabled>
                            <i class="fas fa-check-circle"></i> Proses Pembayaran
                        </button>
                    </div>
                </div>
            </div>

            <!-- HISTORY VIEW -->
            <div id="tab-history" class="history-view" style="display:none">
                <div class="history-card">
                    <div class="history-card-header">
                        <i class="fas fa-table"></i> Riwayat Penjualan Terbaru
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tanggal</th>
                                <th>Kasir</th>
                                <th>Total</th>
                                <th>Bayar</th>
                                <th>Kembalian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            while ($row = mysqli_fetch_assoc($historyResult)):
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= $row['tanggal'] ?></td>
                                    <td><?= htmlspecialchars($row['nama_user'] ?? '-') ?></td>
                                    <td style="font-weight:700;color:var(--green)">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>
                                    <td>Rp <?= number_format($row['bayar'], 0, ',', '.') ?></td>
                                    <td>Rp <?= number_format($row['kembalian'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- end main-content -->

    </div><!-- end app-body -->

    <!-- CONFIRM MODAL -->
    <div class="modal-overlay" id="confirm-modal">
        <div class="modal-box">
            <div class="modal-icon"><i class="fas fa-receipt"></i></div>
            <div class="modal-title">Konfirmasi Pembayaran</div>
            <div class="modal-body" id="modal-body-text">Proses transaksi ini?</div>
            <div class="modal-footer">
                <button class="modal-btn secondary" onclick="closeModal('confirm-modal')">Batal</button>
                <button class="modal-btn primary" onclick="processTransaction()">Ya, Proses</button>
            </div>
        </div>
    </div>

    <!-- SUCCESS MODAL -->
    <div class="modal-overlay" id="success-modal">
        <div class="modal-box">
            <div class="modal-icon"><i class="fas fa-check"></i></div>
            <div class="modal-title">Transaksi Berhasil!</div>
            <div class="modal-body" id="success-body">Penjualan telah diproses.</div>
            <div class="modal-footer">
                <button class="modal-btn primary" onclick="closeModal('success-modal'); resetCart()">Transaksi Baru</button>
            </div>
        </div>
    </div>

    <!-- ERROR MODAL -->
    <div class="modal-overlay" id="error-modal">
        <div class="modal-box">
            <div class="modal-icon error"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="modal-title">Terjadi Kesalahan</div>
            <div class="modal-body" id="error-body">Silakan coba lagi.</div>
            <div class="modal-footer">
                <button class="modal-btn primary" onclick="closeModal('error-modal')">Oke</button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div class="toast" id="toast"></div>

    <script>
        // ── DATA ──
        const products = <?= json_encode($obatList) ?>;

        // Icon styles cycling
        const iconStyles = ['green', 'red', 'amber', 'blue'];
        const iconEmojis = ['💊', '💉', '🧴', '🩺', '🌿', '🍃'];

        let cart = {};
        let currentCategory = 'Semua';
        let searchQuery = '';

        // ── RENDER PRODUCTS ──
        function renderProducts() {
            const grid = document.getElementById('product-grid');
            const filtered = products.filter(p => {
                const matchCat = currentCategory === 'Semua' || (p.nama_kategori || '').toLowerCase().includes(currentCategory.toLowerCase());
                const matchSearch = p.nama_obat.toLowerCase().includes(searchQuery.toLowerCase());
                return matchCat && matchSearch;
            });

            if (!filtered.length) {
                grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted)"><i class="fas fa-search" style="font-size:32px;color:#d0ddd0"></i><p style="margin-top:10px">Obat tidak ditemukan</p></div>`;
                return;
            }

            grid.innerHTML = filtered.map((p, i) => {
                const style = iconStyles[i % iconStyles.length];
                const emoji = iconEmojis[i % iconEmojis.length];
                const inCart = cart[p.id_obat] ? `<div style="position:absolute;top:8px;right:8px;background:var(--green);color:#fff;border-radius:20px;padding:1px 8px;font-size:11px;font-weight:700">${cart[p.id_obat].qty}</div>` : '';
                return `<div class="product-card" onclick="addToCart(${p.id_obat})" style="position:relative">
            ${inCart}
            <div class="product-icon ${style}">${emoji}</div>
            <div class="product-name">${p.nama_obat}</div>
            <div class="product-price">Rp ${formatNum(p.harga_jual)}</div>
            <div class="product-stock">Stok: ${p.stok}</div>
        </div>`;
            }).join('');
        }

        function filterProducts(val) {
            searchQuery = val;
            renderProducts();
        }

        function filterCategory(btn, cat) {
            document.querySelectorAll('.btn-filter').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentCategory = cat;
            renderProducts();
        }

        // ── CART ──
        function addToCart(id) {
            const p = products.find(x => x.id_obat == id);
            if (!p) return;
            if (!cart[id]) {
                cart[id] = {
                    id_obat: id,
                    nama: p.nama_obat,
                    harga: parseFloat(p.harga_jual),
                    qty: 0
                };
            }
            if (cart[id].qty >= parseInt(p.stok)) {
                showToast('Stok tidak cukup!', true);
                return;
            }
            cart[id].qty++;
            renderCart();
            renderProducts();
            updatePayBtn();
        }

        function changeQty(id, delta) {
            if (!cart[id]) return;
            cart[id].qty += delta;
            if (cart[id].qty <= 0) delete cart[id];
            renderCart();
            renderProducts();
            updatePayBtn();
        }

        function renderCart() {
            const container = document.getElementById('cart-items');
            const emptyEl = document.getElementById('empty-cart');
            const keys = Object.keys(cart);

            if (!keys.length) {
                container.innerHTML = `<div class="empty-cart" id="empty-cart">
            <i class="fas fa-cart-plus"></i>
            <p>Keranjang kosong</p>
            <p style="font-size:11px;color:#c0cfc0">Pilih produk di kiri</p>
        </div>`;
                document.getElementById('subtotal-display').textContent = 'Rp 0';
                document.getElementById('total-display').textContent = 'Rp 0';
                calcKembalian();
                return;
            }

            let subtotal = 0;
            container.innerHTML = keys.map(id => {
                const item = cart[id];
                const sub = item.qty * item.harga;
                subtotal += sub;
                return `<div class="cart-item" id="cart-item-${id}">
            <div class="cart-item-info">
                <div class="cart-item-name">${item.nama}</div>
                <div class="cart-item-price">Rp ${formatNum(item.harga)} × ${item.qty}</div>
            </div>
            <div class="qty-ctrl">
                <button class="qty-btn remove" onclick="changeQty(${id}, -1)"><i class="fas fa-minus" style="font-size:10px"></i></button>
                <span class="qty-num">${item.qty}</span>
                <button class="qty-btn" onclick="changeQty(${id}, 1)"><i class="fas fa-plus" style="font-size:10px"></i></button>
            </div>
            <div class="cart-item-subtotal">Rp ${formatNum(sub)}</div>
        </div>`;
            }).join('');

            document.getElementById('subtotal-display').textContent = 'Rp ' + formatNum(subtotal);
            document.getElementById('total-display').textContent = 'Rp ' + formatNum(subtotal);
            calcKembalian();
        }

        function getTotal() {
            return Object.values(cart).reduce((s, i) => s + i.qty * i.harga, 0);
        }

        function calcKembalian() {
            const total = getTotal();
            const bayar = parseFloat(document.getElementById('cash-input').value) || 0;
            const kembalian = bayar - total;
            const row = document.getElementById('kembalian-row');
            if (bayar > 0 && total > 0) {
                row.classList.remove('hidden');
                document.getElementById('kembalian-display').textContent = 'Rp ' + formatNum(kembalian);
                document.getElementById('kembalian-display').style.color = kembalian >= 0 ? 'var(--green)' : 'var(--red)';
            } else {
                row.classList.add('hidden');
            }
            updatePayBtn();
        }

        function togglePaymentFields(val) {
            const cashSection = document.getElementById('cash-section');
            const memberSection = document.getElementById('member-select');
            cashSection.style.display = val === 'cash' ? 'flex' : 'none';
            memberSection.style.display = val === 'bpjs' ? 'block' : 'none';
            updatePayBtn();
        }

        function updatePayBtn() {
            const btn = document.getElementById('btn-pay');
            const total = getTotal();
            const method = document.getElementById('payment-method').value;
            let ok = total > 0;
            if (method === 'cash') {
                const bayar = parseFloat(document.getElementById('cash-input').value) || 0;
                ok = ok && bayar >= total;
            }
            btn.disabled = !ok;
        }

        function resetCart() {
            cart = {};
            document.getElementById('cash-input').value = '';
            renderCart();
            renderProducts();
            updatePayBtn();
        }

        // ── TRANSACTION ──
        function confirmTransaction() {
            const total = getTotal();
            const method = document.getElementById('payment-method').value;
            const bayar = method === 'cash' ? parseFloat(document.getElementById('cash-input').value) : total;
            const kembalian = bayar - total;

            let bodyText = `Total: <strong>Rp ${formatNum(total)}</strong><br>`;
            if (method === 'cash') bodyText += `Bayar: <strong>Rp ${formatNum(bayar)}</strong><br>Kembalian: <strong>Rp ${formatNum(kembalian)}</strong>`;
            else bodyText += `Metode: <strong>${document.getElementById('payment-method').selectedOptions[0].text}</strong>`;

            document.getElementById('modal-body-text').innerHTML = bodyText;
            openModal('confirm-modal');
        }

        function processTransaction() {
            closeModal('confirm-modal');
            const total = getTotal();
            const method = document.getElementById('payment-method').value;
            const bayar = method === 'cash' ? parseFloat(document.getElementById('cash-input').value) : total;
            const items = Object.values(cart).map(i => ({
                id_obat: i.id_obat,
                nama: i.nama,
                harga: i.harga,
                jumlah: i.qty
            }));

            const fd = new FormData();
            fd.append('ajax_transaksi', '1');
            fd.append('items', JSON.stringify(items));
            fd.append('total', total);
            fd.append('bayar', bayar);
            fd.append('metode', method);

            fetch(window.location.href, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const kembalian = data.kembalian;
                        document.getElementById('success-body').innerHTML =
                            `ID Transaksi: <strong>#${data.id_penjualan}</strong><br>` +
                            (kembalian > 0 ? `Kembalian: <strong>Rp ${formatNum(kembalian)}</strong>` : '');
                        openModal('success-modal');
                        // Update product stock in memory
                        items.forEach(item => {
                            const p = products.find(x => x.id_obat == item.id_obat);
                            if (p) p.stok = parseInt(p.stok) - item.jumlah;
                        });
                    } else {
                        document.getElementById('error-body').textContent = data.message;
                        openModal('error-modal');
                    }
                })
                .catch(() => {
                    document.getElementById('error-body').textContent = 'Koneksi gagal. Coba lagi.';
                    openModal('error-modal');
                });
        }

        // ── TABS ──
        function switchTab(tab, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-pos').style.display = tab === 'pos' ? 'flex' : 'none';
            document.getElementById('tab-history').style.display = tab === 'history' ? 'flex' : 'none';
        }

        // ── MODAL ──
        function openModal(id) {
            document.getElementById(id).classList.add('show');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        // ── TOAST ──
        function showToast(msg, error = false) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = 'toast show' + (error ? ' error' : '');
            setTimeout(() => t.className = 'toast', 2500);
        }

        // ── UTILS ──
        function formatNum(n) {
            return Number(n).toLocaleString('id-ID');
        }

        // ── INIT ──
        renderProducts();
        togglePaymentFields('cash');
    </script>

</body>

</html>