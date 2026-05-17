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
$uploadUrl = '../uploads/obat/';

// ══ AJAX: Tambah Pesanan ══
if (isset($_POST['ajax_tambah_pesanan'])) {
  header('Content-Type: application/json');
  if ($isOwner) {
    echo json_encode(['success' => false, 'message' => 'Owner tidak memiliki izin mengubah data.']);
    exit;
  }
  $nama_pemesan = mysqli_real_escape_string($conn, trim($_POST['nama_pemesan'] ?? ''));
  $no_hp        = mysqli_real_escape_string($conn, trim($_POST['no_hp'] ?? ''));
  $alamat       = mysqli_real_escape_string($conn, trim($_POST['alamat'] ?? ''));
  $catatan      = mysqli_real_escape_string($conn, trim($_POST['catatan'] ?? ''));
  $items        = json_decode($_POST['items'], true);
  $dp           = (float)($_POST['dp'] ?? 0);
  $total        = (float)$_POST['total'];
  $metode_dp    = mysqli_real_escape_string($conn, $_POST['metode_dp'] ?? 'Tunai');
  $id_member    = (!empty($_POST['id_member'])) ? (int)$_POST['id_member'] : null;

  if (!$nama_pemesan || !$no_hp || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Nama, No. HP, dan item pesanan wajib diisi!']);
    exit;
  }

  $min_dp = $total * 0.5;
  if ($dp < $min_dp) {
    echo json_encode(['success' => false, 'message' => 'DP minimal 50% dari total (Rp ' . number_format($min_dp, 0, ',', '.') . ')!']);
    exit;
  }
  if ($dp > $total) {
    echo json_encode(['success' => false, 'message' => 'Nominal DP tidak boleh melebihi total!']);
    exit;
  }

  $id_member_sql = $id_member ? "'$id_member'" : 'NULL';
  $sisa_bayar   = $total - $dp;
  $status       = ($dp >= $total) ? 'lunas' : ($dp > 0 ? 'dp' : 'pending');
  $ok = mysqli_query($conn, "INSERT INTO pesanan (nama_pemesan,no_hp,alamat,catatan,total,dp,sisa_bayar,metode_dp,status,tanggal_pesan,id_user,id_member)
    VALUES ('$nama_pemesan','$no_hp','$alamat','$catatan','$total','$dp','$sisa_bayar','$metode_dp','$status',NOW(),'{$user['id_user']}',$id_member_sql)");
  if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Gagal: ' . mysqli_error($conn)]);
    exit;
  }
  $id_pesanan = mysqli_insert_id($conn);
  foreach ($items as $item) {
    $io  = (int)$item['id_obat'];
    $jml = (int)$item['jumlah'];
    $hrg = (float)$item['harga'];
    $sub = $jml * $hrg;
    mysqli_query($conn, "INSERT INTO detail_pesanan (id_pesanan,id_obat,jumlah,harga,subtotal) VALUES ('$id_pesanan','$io','$jml','$hrg','$sub')");
  }
  if ($dp > 0) mysqli_query($conn, "INSERT INTO pembayaran_pesanan (id_pesanan,jumlah,metode,keterangan,tanggal) VALUES ('$id_pesanan','$dp','$metode_dp','DP Awal',NOW())");
  echo json_encode(['success' => true, 'id_pesanan' => $id_pesanan]);
  exit;
}

// ══ AJAX: Cek stok ready ══
if (isset($_GET['ajax_cek_stok'])) {
  header('Content-Type: application/json');
  $id_pesanan = (int)$_GET['id_pesanan'];
  $det = mysqli_query($conn, "SELECT * FROM detail_pesanan WHERE id_pesanan='$id_pesanan'");
  $notReady = [];
  while ($d = mysqli_fetch_assoc($det)) {
    $io  = (int)$d['id_obat'];
    $jml = (int)$d['jumlah'];
    $qb  = mysqli_query($conn, "SELECT SUM(stok_sisa) as total FROM pembelian WHERE id_obat='$io' AND stok_sisa>0");
    $totStok = mysqli_fetch_assoc($qb)['total'] ?? 0;
    if ($totStok < $jml) {
      $prod = mysqli_fetch_assoc(mysqli_query($conn, "SELECT nama_obat FROM obat WHERE id_obat='$io'"));
      $notReady[] = $prod['nama_obat'] . " (butuh $jml, tersedia " . intval($totStok) . ")";
    }
  }
  echo json_encode($notReady ? ['ready' => false, 'items' => $notReady] : ['ready' => true]);
  exit;
}

// ══ AJAX: Lunasi ══
if (isset($_POST['ajax_lunasi'])) {
  header('Content-Type: application/json');
  if ($isOwner) {
    echo json_encode(['success' => false, 'message' => 'Owner tidak memiliki izin.']);
    exit;
  }
  $id_pesanan = (int)$_POST['id_pesanan'];
  $bayar      = (float)$_POST['bayar'];
  $metode     = mysqli_real_escape_string($conn, $_POST['metode'] ?? 'Tunai');
  $p          = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pesanan WHERE id_pesanan='$id_pesanan'"));
  if (!$p) {
    echo json_encode(['success' => false, 'message' => 'Pesanan tidak ditemukan!']);
    exit;
  }
  if ($bayar < $p['sisa_bayar']) {
    echo json_encode(['success' => false, 'message' => 'Pembayaran kurang! Sisa: Rp ' . number_format($p['sisa_bayar'], 0, ',', '.')]);
    exit;
  }
  $kembalian = $bayar - $p['sisa_bayar'];
  mysqli_query($conn, "UPDATE pesanan SET sisa_bayar=0,status='lunas',tanggal_lunas=NOW() WHERE id_pesanan='$id_pesanan'");
  mysqli_query($conn, "INSERT INTO pembayaran_pesanan (id_pesanan,jumlah,metode,keterangan,tanggal) VALUES ('$id_pesanan','{$p['sisa_bayar']}','$metode','Pelunasan',NOW())");

  $det = mysqli_query($conn, "SELECT * FROM detail_pesanan WHERE id_pesanan='$id_pesanan'");
  while ($d = mysqli_fetch_assoc($det)) {
    $io  = (int)$d['id_obat'];
    $jml = (int)$d['jumlah'];
    $cek = mysqli_fetch_assoc(mysqli_query($conn, "SELECT stok FROM obat WHERE id_obat='$io'"));
    if ($cek && $cek['stok'] >= $jml) {
      mysqli_query($conn, "UPDATE obat SET stok=stok-$jml WHERE id_obat='$io'");
      $sisa = $jml;
      $qb   = mysqli_query($conn, "SELECT * FROM pembelian WHERE id_obat='$io' AND stok_sisa>0 ORDER BY expired_date ASC,id_pembelian ASC");
      while (($batch = mysqli_fetch_assoc($qb)) && $sisa > 0) {
        $ambil = min($batch['stok_sisa'], $sisa);
        mysqli_query($conn, "UPDATE pembelian SET stok_sisa=stok_sisa-$ambil WHERE id_pembelian='{$batch['id_pembelian']}'");
        mysqli_query($conn, "INSERT INTO stok_keluar(id_obat,tanggal,jumlah,keterangan) VALUES('$io',NOW(),'$ambil','Pesanan ID $id_pesanan')");
        $sisa -= $ambil;
      }
    }
  }

  $metodeMap = ['Tunai' => 'Tunai', 'Transfer Bank' => 'Tranfer_Bank', 'E-Wallet' => 'E_Wallet'];
  $metode_db = $metodeMap[$metode] ?? 'Tunai';
  $totalP    = $p['total'];
  $idMemberSql = $p['id_member'] ? "'" . $p['id_member'] . "'" : 'NULL';
  mysqli_query($conn, "INSERT INTO penjualan(tanggal,total,bayar,kembalian,metode_pembayaran,id_user,id_member) VALUES(NOW(),'$totalP','$totalP','0','$metode_db','{$user['id_user']}',$idMemberSql)");
  $idj  = mysqli_insert_id($conn);
  $det2 = mysqli_query($conn, "SELECT * FROM detail_pesanan WHERE id_pesanan='$id_pesanan'");
  while ($d = mysqli_fetch_assoc($det2)) {
    $sub = $d['jumlah'] * $d['harga'];
    mysqli_query($conn, "INSERT INTO detail_penjualan(id_penjualan,id_obat,jumlah,harga_jual,subtotal) VALUES('$idj','{$d['id_obat']}','{$d['jumlah']}','{$d['harga']}','$sub')");
  }
  mysqli_query($conn, "UPDATE pesanan SET status='selesai' WHERE id_pesanan='$id_pesanan'");
  echo json_encode(['success' => true, 'kembalian' => $kembalian]);
  exit;
}

// ══ AJAX: Batalkan ══
if (isset($_POST['ajax_batal'])) {
  header('Content-Type: application/json');
  if ($isOwner) {
    echo json_encode(['success' => false, 'message' => 'Owner tidak memiliki izin.']);
    exit;
  }
  $id_pesanan = (int)$_POST['id_pesanan'];
  $alasan     = mysqli_real_escape_string($conn, trim($_POST['alasan'] ?? ''));
  $p          = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pesanan WHERE id_pesanan='$id_pesanan'"));
  if (!$p || in_array($p['status'], ['selesai', 'batal'])) {
    echo json_encode(['success' => false, 'message' => 'Tidak dapat dibatalkan.']);
    exit;
  }
  mysqli_query($conn, "UPDATE pesanan SET status='batal',alasan_batal='$alasan',tanggal_batal=NOW() WHERE id_pesanan='$id_pesanan'");
  echo json_encode(['success' => true, 'dp_hangus' => $p['dp']]);
  exit;
}

// ══ AJAX: Detail ══
if (isset($_GET['ajax_detail'])) {
  header('Content-Type: application/json');
  $id   = (int)$_GET['id_pesanan'];
  $rows = [];
  $q    = mysqli_query($conn, "SELECT dp.*,o.nama_obat FROM detail_pesanan dp LEFT JOIN obat o ON dp.id_obat=o.id_obat WHERE dp.id_pesanan='$id'");
  while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
  $pay = [];
  $qp  = mysqli_query($conn, "SELECT * FROM pembayaran_pesanan WHERE id_pesanan='$id' ORDER BY id_pembayaran_pesanan ASC");
  while ($r = mysqli_fetch_assoc($qp)) $pay[] = $r;
  echo json_encode(['items' => $rows, 'payments' => $pay]);
  exit;
}

// ══ Fetch data ══
$obatResult = mysqli_query($conn, "SELECT o.*,k.nama_kategori FROM obat o LEFT JOIN kategori k ON o.id_kategori=k.id_kategori ORDER BY o.nama_obat ASC");
$obatList   = [];
while ($row = mysqli_fetch_assoc($obatResult)) $obatList[] = $row;

$kategoriResult = mysqli_query($conn, "SELECT DISTINCT k.id_kategori,k.nama_kategori FROM kategori k INNER JOIN obat o ON k.id_kategori=o.id_kategori ORDER BY k.nama_kategori ASC");
$kategoriList   = [];
while ($k = mysqli_fetch_assoc($kategoriResult)) $kategoriList[] = $k;

$memberResult = mysqli_query($conn, "SELECT * FROM member ORDER BY nama_lengkap ASC");
$memberList   = [];
while ($row = mysqli_fetch_assoc($memberResult)) $memberList[] = $row;

$pesananResult = mysqli_query($conn, "SELECT p.*,u.nama_user,m.nama_lengkap as nama_member FROM pesanan p LEFT JOIN users u ON p.id_user=u.id_user LEFT JOIN member m ON p.id_member=m.id_member WHERE p.status NOT IN ('batal') ORDER BY p.id_pesanan DESC LIMIT 50");
$batalResult   = mysqli_query($conn, "SELECT p.*,u.nama_user,m.nama_lengkap as nama_member FROM pesanan p LEFT JOIN users u ON p.id_user=u.id_user LEFT JOIN member m ON p.id_member=m.id_member WHERE p.status='batal' ORDER BY p.tanggal_batal DESC LIMIT 50");
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Pesanan / Pre-Order — Apotek</title>
  <link rel="stylesheet" href="../css/navigation.css">
  <link rel="stylesheet" href="../css/pesanan.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;300;400;500;600;700;800&display=swap" rel="stylesheet">
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
      <span class="current">Pesanan</span>
    </div>
    <div class="topnav-right">
      <!-- FIX: id disamakan dengan JS (ddwrap/ddmenu), fungsi toggleDropdown() diperbaiki -->
      <div class="user-info ddwrap" id="ddwrap" onclick="toggleDropdown()">
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
        <a class="sb-link" href="penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
        <a class="sb-link active" href="pesanan.php"><i class="fas fa-box"></i> Pesanan</a>
        <div class="sb-sec">Laporan</div>
        <a class="sb-link" href="../laporan/laporan_penjualan.php"><i class="fas fa-chart-line"></i> Penjualan</a>
        <a class="sb-link" href="../laporan/laporan_pembelian.php"><i class="fas fa-chart-bar"></i> Pembelian</a>
        <a class="sb-link" href="../laporan/laporan_stok.php"><i class="fas fa-boxes"></i> Stok</a>
      <?php elseif ($user['role'] == 'admin'): ?>
        <div class="sb-sec">Transaksi</div>
        <a class="sb-link" href="pembelian.php"><i class="fas fa-shopping-bag"></i> Pembelian</a>
      <?php elseif ($user['role'] == 'kasir'): ?>
        <div class="sb-sec">Transaksi</div>
        <a class="sb-link" href="penjualan.php"><i class="fas fa-cash-register"></i> Penjualan</a>
        <a class="sb-link active" href="pesanan.php"><i class="fas fa-box"></i> Pesanan</a>
      <?php endif; ?>

      <div class="sb-footer">
        <div class="small">Masuk sebagai</div>
        <strong><?= htmlspecialchars($user['nama_user']) ?></strong>
      </div>
    </aside>

    <!-- MAIN -->
    <div class="main">
      <!-- TABS -->
      <div class="tabs">
        <button class="tab-btn active" onclick="swTab('kasir',this)">
          <i class="fas fa-box"></i> Kasir Pesanan
        </button>
        <button class="tab-btn" onclick="swTab('riwayat',this)">
          <i class="fas fa-list-alt"></i> Riwayat Pesanan
        </button>
        <button class="tab-btn" onclick="swTab('batal',this)">
          <i class="fas fa-ban"></i> Riwayat Pembatalan
        </button>
      </div>

      <!-- ═══ TAB 1: KASIR ═══ -->
      <div id="tab-kasir" class="panel active">
        <div class="pos-wrap">
          <!-- LEFT PANEL -->
          <div class="pos-left">
            <!-- Pencarian & Kategori -->
            <div class="sec-card">
              <div class="sec-card-body">
                <div class="search-wrap">
                  <i class="fas fa-search"></i>
                  <input placeholder="Cari nama obat atau scan barcode…" oninput="doFilter(this.value)" <?= $isOwner ? 'disabled' : '' ?>>
                </div>
                <div class="cat-pills">
                  <button class="cp active" onclick="doCat(this,'Semua')">Semua</button>
                  <?php foreach ($kategoriList as $k): ?>
                    <button class="cp" onclick="doCat(this,'<?= addslashes(htmlspecialchars($k['nama_kategori'])) ?>')"><?= htmlspecialchars($k['nama_kategori']) ?></button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <!-- Product Grid -->
            <div class="prod-scroll">
              <div class="prod-grid" id="po-grid"></div>
            </div>
          </div>

          <!-- CART RIGHT PANEL -->
          <div class="pos-cart">
            <div class="cart-hdr">
              <i class="fas fa-shopping-cart"></i> Keranjang
              <span class="cart-count" id="cart-count">0</span>
            </div>
            <div class="cart-body" id="po-cart"></div>
            <div class="cart-foot">
              <!-- Member -->
              <div>
                <div class="cf-lbl"><i class="fas fa-id-card" style="color:var(--violet)"></i> Member</div>
                <select class="inp-field" id="po-member" onchange="onMember(this)" <?= $isOwner ? 'disabled' : '' ?>>
                  <option value="">— Pilih member (opsional) —</option>
                  <?php foreach ($memberList as $m): ?>
                    <option value="<?= $m['id_member'] ?>"
                      data-nama="<?= htmlspecialchars($m['nama_lengkap']) ?>"
                      data-hp="<?= htmlspecialchars($m['no_hp']) ?>"
                      data-alamat="<?= htmlspecialchars($m['alamat'] ?? '') ?>">
                      <?= htmlspecialchars($m['nama_lengkap']) ?> — <?= htmlspecialchars($m['no_hp']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <!-- Member chip -->
                <div id="member-chip" class="member-chip hidden">
                  <div class="mc-top">
                    <div class="mc-av" id="mc-av">—</div>
                    <div class="mc-info">
                      <div class="mc-name" id="mc-nm">—</div>
                    </div>
                    <button class="mc-clear" onclick="clrMbr()" title="Hapus member"><i class="fas fa-times"></i></button>
                  </div>
                  <div class="mc-details" id="mc-details"></div>
                </div>
              </div>
              <!-- Hidden pemesan fields -->
              <div class="hidden">
                <input type="hidden" id="po-nama" value="Tamu">
                <input type="hidden" id="po-hp" value="0000000000">
                <input type="hidden" id="po-alamat" value="">
              </div>
              <!-- Catatan -->
              <div>
                <label style="font-size:10px;font-weight:700;color:var(--ink-4);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;display:block">
                  Catatan
                </label>
                <input class="pf-inp" id="po-catatan" placeholder="Catatan pesanan (opsional)" <?= $isOwner ? 'disabled' : '' ?>>
              </div>
              <!-- Summary -->
              <div>
                <div class="sum-row"><span class="sl">Subtotal</span><span id="s-sub">Rp 0</span></div>
                <div class="sum-row total"><span>Total</span><span class="sv" id="s-total">Rp 0</span></div>
              </div>
              <!-- DP -->
              <div class="dp-ttl"><i class="fas fa-hand-holding-usd"></i> Uang Muka (DP)</div>
              <div class="dp-min-info"><i class="fas fa-info-circle"></i> DP minimal <strong>50%</strong> dari total — <span id="dp-min-show">Rp 0</span></div>
              <div class="dp-row">
                <select class="dp-sel" id="po-met-dp" <?= $isOwner ? 'disabled' : '' ?>>
                  <option value="Tunai">💵 Tunai</option>
                  <option value="Transfer Bank">🏦 Transfer</option>
                  <option value="E-Wallet">📱 E-Wallet</option>
                </select>
                <input type="number" class="dp-inp" id="po-dp" placeholder="Nominal DP (min. 50%)" oninput="calcSisa()" <?= $isOwner ? 'disabled' : '' ?>>
              </div>
              <div class="sisa-row">
                <span>Sisa bayar nanti</span>
                <span class="sisa-v" id="po-sisa">Rp 0</span>
              </div>
              <div class="dp-warn hidden" id="dp-warn">
                <i class="fas fa-exclamation-circle"></i> DP minimal 50% dari total (<span id="dp-warn-min">Rp 0</span>)
              </div>
              <button class="btn-pay" id="btn-pesan" onclick="confirmPesan()" disabled>
                <i class="fas fa-paper-plane"></i> Buat Pesanan
              </button>
            </div>
          </div>

        </div>
      </div>

      <!-- ═══ TAB 2: RIWAYAT ═══ -->
      <div id="tab-riwayat" class="panel">
        <div class="hist-wrap">
          <div class="tbl-card">
            <div class="tbl-hdr-strip">
              <i class="fas fa-list-alt" style="color:var(--leaf-3)"></i>
              Daftar Pesanan Masuk
            </div>
            <?php
            $no   = 1;
            $rows = [];
            while ($r = mysqli_fetch_assoc($pesananResult)) $rows[] = $r;
            if (!$rows): ?>
              <div class="po-empty"><i class="fas fa-inbox"></i>
                <p>Belum ada pesanan masuk</p>
              </div>
              <?php else: foreach ($rows as $row):
                $dp   = (float)$row['dp'];
                $sisa = (float)$row['sisa_bayar'];
                $tot  = (float)$row['total'];
                $st   = $row['status'];
                $bc   = ['dp' => 'b-dp', 'lunas' => 'b-lunas', 'selesai' => 'b-selesai', 'pending' => 'b-pending'][$st] ?? 'b-pending';
                $bl   = ['dp' => 'DP Dibayar', 'lunas' => 'Lunas', 'selesai' => 'Selesai', 'pending' => 'Menunggu DP'][$st] ?? $st;
                $bi   = ['dp' => 'clock', 'lunas' => 'check-circle', 'selesai' => 'check-double', 'pending' => 'hourglass-half'][$st] ?? 'clock';
              ?>
                <div class="po-row" id="row-<?= $row['id_pesanan'] ?>">
                  <div class="po-num">#<?= $no++ ?></div>
                  <div class="po-info">
                    <div class="po-nm"><?= htmlspecialchars($row['nama_pemesan']) ?></div>
                    <div class="po-meta">
                      <span><i class="fas fa-phone"></i><?= htmlspecialchars($row['no_hp']) ?></span>
                      <span><i class="fas fa-calendar-alt"></i><?= date('d M Y, H:i', strtotime($row['tanggal_pesan'])) ?></span>
                      <?php if ($row['nama_member']): ?>
                        <span style="color:var(--violet)"><i class="fas fa-user-tag" style="color:var(--violet)"></i><?= htmlspecialchars($row['nama_member']) ?></span>
                      <?php endif; ?>
                      <span><i class="fas fa-user-circle"></i><?= htmlspecialchars($row['nama_user'] ?? '—') ?></span>
                    </div>
                    <div class="po-badges">
                      <span class="badge <?= $bc ?>"><i class="fas fa-<?= $bi ?>"></i> <?= $bl ?></span>
                      <?php if ($row['alamat']): ?><span class="badge b-addr"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($row['alamat']) ?></span><?php endif; ?>
                      <?php if ($row['catatan']): ?><span class="badge b-note"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($row['catatan']) ?></span><?php endif; ?>
                    </div>
                    <div class="po-acts">
                      <button class="btn-sm bs-detail" onclick="openDetail(<?= $row['id_pesanan'] ?>,'<?= htmlspecialchars($row['nama_pemesan'], ENT_QUOTES) ?>')"><i class="fas fa-eye"></i> Detail</button>
                      <?php if (in_array($st, ['dp', 'pending'])): ?>
                        <button class="btn-sm bs-lunasi" onclick="openLunasi(<?= $row['id_pesanan'] ?>,<?= $sisa ?>,'<?= htmlspecialchars($row['nama_pemesan'], ENT_QUOTES) ?>')"><i class="fas fa-check-circle"></i> Lunasi</button>
                      <?php endif; ?>
                      <?php if (!in_array($st, ['selesai', 'batal'])): ?>
                        <button class="btn-sm bs-batal" onclick="openBatal(<?= $row['id_pesanan'] ?>,<?= $dp ?>,'<?= htmlspecialchars($row['nama_pemesan'], ENT_QUOTES) ?>')"><i class="fas fa-times-circle"></i> Batalkan</button>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="po-right">
                    <div class="po-total">Rp <?= number_format($tot, 0, ',', '.') ?></div>
                    <div class="po-fin">DP: <span class="dv">Rp <?= number_format($dp, 0, ',', '.') ?></span></div>
                    <?php if ($sisa > 0): ?>
                      <div class="po-fin">Sisa: <span class="sv">Rp <?= number_format($sisa, 0, ',', '.') ?></span></div>
                    <?php endif; ?>
                  </div>
                </div>
            <?php endforeach;
            endif; ?>
          </div>
        </div>
      </div>

      <!-- ═══ TAB 3: PEMBATALAN ═══ -->
      <div id="tab-batal" class="panel">
        <div class="hist-wrap">
          <div class="tbl-card">
            <div class="tbl-hdr-strip" style="border-left:3px solid var(--rose);padding-left:17px">
              <i class="fas fa-ban" style="color:var(--rose)"></i> Pesanan Dibatalkan
            </div>
            <?php
            $no2   = 1;
            $brows = [];
            while ($r = mysqli_fetch_assoc($batalResult)) $brows[] = $r;
            if (!$brows): ?>
              <div class="po-empty"><i class="fas fa-check-circle" style="color:var(--leaf-3)"></i>
                <p>Tidak ada pesanan yang dibatalkan</p>
              </div>
              <?php else: foreach ($brows as $row):
                $dp  = (float)$row['dp'];
                $tot = (float)$row['total'];
              ?>
                <div class="po-row batal-row">
                  <div class="po-num">#<?= $no2++ ?></div>
                  <div class="po-info">
                    <div class="po-nm"><?= htmlspecialchars($row['nama_pemesan']) ?></div>
                    <div class="po-meta">
                      <span><i class="fas fa-phone"></i><?= htmlspecialchars($row['no_hp']) ?></span>
                      <span><i class="fas fa-calendar-alt"></i>Pesan: <?= date('d M Y', strtotime($row['tanggal_pesan'])) ?></span>
                      <?php if ($row['tanggal_batal']): ?>
                        <span style="color:var(--rose)"><i class="fas fa-calendar-times" style="color:var(--rose)"></i>Batal: <?= date('d M Y H:i', strtotime($row['tanggal_batal'])) ?></span>
                      <?php endif; ?>
                      <span><i class="fas fa-user-circle"></i><?= htmlspecialchars($row['nama_user'] ?? '—') ?></span>
                    </div>
                    <?php if ($row['alasan_batal']): ?>
                      <div class="alasan-pill"><i class="fas fa-comment-alt"></i><span>Alasan: <?= htmlspecialchars($row['alasan_batal']) ?></span></div>
                    <?php endif; ?>
                    <div style="margin-top:8px">
                      <button class="btn-sm bs-detail" onclick="openDetail(<?= $row['id_pesanan'] ?>,'<?= htmlspecialchars($row['nama_pemesan'], ENT_QUOTES) ?>')"><i class="fas fa-eye"></i> Lihat Detail</button>
                    </div>
                  </div>
                  <div class="po-right">
                    <div class="po-total" style="color:var(--ink-4);text-decoration:line-through">Rp <?= number_format($tot, 0, ',', '.') ?></div>
                    <?php if ($dp > 0): ?>
                      <div style="margin-top:5px"><span class="dp-hangus"><i class="fas fa-fire"></i> Hangus: Rp <?= number_format($dp, 0, ',', '.') ?></span></div>
                    <?php else: ?>
                      <div class="po-fin">Tanpa DP</div>
                    <?php endif; ?>
                  </div>
                </div>
            <?php endforeach;
            endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- Konfirmasi Pesanan -->
  <div class="overlay" id="modal-confirm">
    <div class="modal">
      <div class="m-hdr">
        <div class="m-ico orange"><i class="fas fa-paper-plane"></i></div>
        <div class="m-ttl">Konfirmasi Pesanan</div>
        <div class="m-sub">Periksa detail sebelum menyimpan</div>
      </div>
      <div class="m-body">
        <div class="c-box" id="c-body"></div>
      </div>
      <div class="m-foot">
        <button class="mbtn sec" onclick="closeM('modal-confirm')">Kembali</button>
        <button class="mbtn ora" onclick="doSubmit()"><i class="fas fa-check"></i> Ya, Buat Pesanan</button>
      </div>
    </div>
  </div>

  <!-- Sukses -->
  <div class="overlay" id="modal-sukses">
    <div class="modal">
      <div class="m-hdr">
        <div class="m-ico green"><i class="fas fa-check-circle"></i></div>
        <div class="m-ttl">Pesanan Berhasil Dibuat!</div>
        <div class="m-sub" id="sukses-sub">—</div>
      </div>
      <div class="m-body"></div>
      <div class="m-foot">
        <button class="mbtn sec" onclick="location.reload()"><i class="fas fa-plus"></i> Pesanan Baru</button>
        <button class="mbtn pri" onclick="goRiwayat()"><i class="fas fa-list-alt"></i> Lihat Riwayat</button>
      </div>
    </div>
  </div>

  <!-- Lunasi -->
  <div class="overlay" id="modal-lunasi">
    <div class="modal">
      <div class="m-hdr">
        <div class="m-ico green"><i class="fas fa-receipt"></i></div>
        <div class="m-ttl">Lunasi Pesanan</div>
        <div class="m-sub" id="ln-name">—</div>
      </div>
      <div class="m-body">
        <div class="sisa-hl">
          <div class="amount" id="ln-amount">Rp 0</div>
          <div class="subl">Sisa yang harus dilunasi</div>
        </div>
        <div class="mfg">
          <label>Metode Pembayaran</label>
          <select class="mfi" id="ln-met">
            <option value="Tunai">💵 Tunai</option>
            <option value="Transfer Bank">🏦 Transfer Bank</option>
            <option value="E-Wallet">📱 E-Wallet</option>
          </select>
        </div>
        <div class="mfg">
          <label>Jumlah Bayar</label>
          <input type="number" class="mfi" id="ln-bayar" placeholder="Masukkan nominal…" oninput="calcLnKemb()" style="font-family:'DM Mono',monospace;font-size:16px;font-weight:700">
        </div>
        <div id="ln-kemb" class="kemb-row hidden">
          <span style="color:var(--ink-3)">Kembalian</span>
          <span id="ln-kemb-val" style="font-weight:800;color:var(--leaf-2);font-family:'DM Mono',monospace">Rp 0</span>
        </div>
      </div>
      <div class="m-foot">
        <button class="mbtn sec" onclick="closeM('modal-lunasi')">Batal</button>
        <button class="mbtn pri" id="btn-ln" onclick="doLunasi()" disabled><i class="fas fa-check-circle"></i> Lunasi Sekarang</button>
      </div>
    </div>
  </div>

  <!-- Batal -->
  <div class="overlay" id="modal-batal">
    <div class="modal">
      <div class="m-hdr">
        <div class="m-ico red"><i class="fas fa-times-circle"></i></div>
        <div class="m-ttl">Batalkan Pesanan</div>
        <div class="m-sub" id="bt-sub">Pesanan akan dibatalkan.</div>
      </div>
      <div class="m-body">
        <div class="mfg">
          <label>Alasan Pembatalan</label>
          <input class="mfi" id="bt-alasan" placeholder="Tuliskan alasan…">
        </div>
        <div class="alert-warn">
          <i class="fas fa-exclamation-triangle"></i>
          <span>DP sebesar <strong id="bt-dp">Rp 0</strong> akan <strong>hangus</strong> dan tidak dikembalikan.</span>
        </div>
      </div>
      <div class="m-foot">
        <button class="mbtn sec" onclick="closeM('modal-batal')">Kembali</button>
        <button class="mbtn rd" onclick="doBatal()"><i class="fas fa-trash"></i> Batalkan</button>
      </div>
    </div>
  </div>

  <!-- Detail -->
  <div class="overlay" id="modal-detail">
    <div class="modal wide">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 22px 14px;border-bottom:1px solid var(--border)">
        <div style="font-size:15px;font-weight:800" id="det-title">Detail Pesanan</div>
        <button onclick="closeM('modal-detail')" style="width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:var(--surface-2);cursor:pointer;color:var(--ink-3);font-size:12px">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div id="det-body" style="padding:18px 22px;display:flex;flex-direction:column;gap:16px;max-height:68vh;overflow-y:auto">
        <div style="text-align:center;padding:30px;color:var(--ink-3)"><i class="fas fa-spinner fa-spin" style="font-size:24px"></i></div>
      </div>
    </div>
  </div>

  <!-- Error -->
  <div class="overlay" id="modal-error">
    <div class="modal">
      <div class="m-hdr">
        <div class="m-ico red"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="m-ttl">Terjadi Kesalahan</div>
        <div class="m-sub" id="err-msg">Silakan coba lagi.</div>
      </div>
      <div class="m-foot" style="margin-top:10px">
        <button class="mbtn pri" onclick="closeM('modal-error')">Oke</button>
      </div>
    </div>
  </div>

  <div class="toast" id="toast"></div>

  <script>
    const PRODS = <?= json_encode($obatList) ?>;
    const UP = '<?= $uploadUrl ?>';
    const OWNER = <?= $isOwner ? 'true' : 'false' ?>;
    const ICONS = ['c0', 'c1', 'c2', 'c3', 'c4', 'c5'];
    const EMOJIS = ['💊', '💉', '🧴', '🩺', '🌿', '🍃'];

    let cart = {},
      curCat = 'Semua',
      curQ = '';
    let member = null,
      lnId = 0,
      lnSisa = 0,
      btId = 0;

    /* ── Util ── */
    function $(id) {
      return document.getElementById(id);
    }

    function fmt(n) {
      return Number(n).toLocaleString('id-ID');
    }

    /* ── [FIX] Dropdown: nama fungsi disesuaikan dengan onclick di HTML ── */
    function toggleDropdown() {
      $('ddmenu').classList.toggle('open');
    }
    document.addEventListener('click', function(e) {
      const wrap = $('ddwrap');
      if (wrap && !wrap.contains(e.target)) {
        $('ddmenu').classList.remove('open');
      }
    });

    /* ── Member ── */
    function onMember(sel) {
      const id = sel.value;
      if (!id) {
        clrMbr();
        return;
      }
      const opt = sel.options[sel.selectedIndex];
      const nama = opt.dataset.nama || 'Tamu';
      const hp = opt.dataset.hp || '0000000000';
      const alamat = opt.dataset.alamat || '';
      member = {
        id,
        nama,
        hp,
        alamat
      };

      $('mc-av').textContent = nama.charAt(0).toUpperCase();
      $('mc-nm').textContent = nama;

      let det = '';
      if (hp) det += `<div class="mc-detail-row"><i class="fas fa-phone"></i>${hp}</div>`;
      if (alamat) det += `<div class="mc-detail-row"><i class="fas fa-map-marker-alt"></i>${alamat}</div>`;
      if (!hp && !alamat) det = `<div class="mc-detail-row" style="color:var(--ink-4)"><i class="fas fa-info-circle"></i>Data kontak tidak tersedia</div>`;
      $('mc-details').innerHTML = det;
      $('member-chip').classList.remove('hidden');

      if (!OWNER) {
        $('po-nama').value = nama;
        $('po-hp').value = hp;
        $('po-alamat').value = alamat;
      }
      updTotals();
      updBtn();
    }

    function clrMbr() {
      member = null;
      $('po-member').value = '';
      $('member-chip').classList.add('hidden');
      $('po-nama').value = 'Tamu';
      $('po-hp').value = '0000000000';
      $('po-alamat').value = '';
      updTotals();
      updBtn();
    }

    /* ── Products ── */
    function renderGrid() {
      const g = $('po-grid');
      const list = PRODS.filter(p => {
        const mc = curCat === 'Semua' || (p.nama_kategori || '').toLowerCase() === curCat.toLowerCase();
        const ms = p.nama_obat.toLowerCase().includes(curQ.toLowerCase());
        return mc && ms;
      });
      if (!list.length) {
        g.innerHTML = `<div class="empty-grid"><i class="fas fa-search"></i><p>Obat tidak ditemukan</p></div>`;
        return;
      }
      g.innerHTML = list.map((p, i) => {
        const ic = ICONS[i % ICONS.length];
        const em = EMOJIS[i % EMOJIS.length];
        const stok = parseInt(p.stok) || 0;
        const outOfStock = stok <= 0;
        const inC = !!cart[p.id_obat];

        const stokHabisBadge = outOfStock ?
          `<div class="stok-habis-badge"><i class="fas fa-times-circle"></i> STOK HABIS</div>` :
          '';

        const badge = inC ? `<div class="prod-badge">${cart[p.id_obat].qty}</div>` : '';

        const img = p.gambar ?
          `<img src="${UP}${p.gambar}" class="prod-img" alt="${p.nama_obat}">` :
          `<div class="prod-ico ${ic}">${em}</div>`;

        const clickAttr = OWNER ?
          `style="cursor:default;opacity:.7"` :
          `onclick="addCart(${p.id_obat})"`;

        const stokText = outOfStock ?
          `<div class="prod-stok empty">Stok habis</div>` :
          `<div class="prod-stok">Stok: ${stok}</div>`;

        return `<div class="prod-card${inC ? ' in-cart' : ''}${outOfStock ? ' out-of-stock' : ''}" ${clickAttr}>
          ${stokHabisBadge}${badge}${img}
          <div class="prod-name">${p.nama_obat}</div>
          <div class="prod-price">Rp ${fmt(p.harga_jual)}</div>
          ${stokText}
        </div>`;
      }).join('');
    }

    function doFilter(v) {
      curQ = v;
      renderGrid();
    }

    function doCat(btn, cat) {
      document.querySelectorAll('.cp').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      curCat = cat;
      renderGrid();
    }

    /* ── Cart ── */
    function addCart(id) {
      if (OWNER) return;
      const p = PRODS.find(x => x.id_obat == id);
      if (!p) return;
      const stok = parseInt(p.stok) || 0;
      if (!cart[id]) cart[id] = {
        id_obat: id,
        nama: p.nama_obat,
        harga: parseFloat(p.harga_jual),
        qty: 0
      };
      if (stok > 0 && cart[id].qty >= stok) {
        toast('Stok tidak mencukupi!', true);
        return;
      }
      cart[id].qty++;
      renderCart();
      renderGrid();
      updBtn();
    }

    function chgQty(id, d) {
      if (!cart[id]) return;
      cart[id].qty += d;
      if (cart[id].qty <= 0) delete cart[id];
      renderCart();
      renderGrid();
      updBtn();
    }

    function renderCart() {
      const el = $('po-cart');
      const keys = Object.keys(cart);
      const count = Object.values(cart).reduce((s, i) => s + i.qty, 0);
      $('cart-count').textContent = count;
      if (!keys.length) {
        el.innerHTML = `<div class="cart-empty"><i class="fas fa-cart-plus"></i><p>Keranjang kosong</p><small>Pilih produk dari kiri</small></div>`;
        updTotals();
        return;
      }
      el.innerHTML = keys.map(id => {
        const it = cart[id],
          sb = it.qty * it.harga;
        return `<div class="c-item">
          <div class="ci-info">
            <div class="ci-nm">${it.nama}</div>
            <div class="ci-pr">Rp ${fmt(it.harga)} × ${it.qty}</div>
          </div>
          <div class="qty-w">
            <button class="q-btn rm" onclick="chgQty(${id},-1)"><i class="fas fa-minus" style="font-size:8px"></i></button>
            <span class="q-num">${it.qty}</span>
            <button class="q-btn" onclick="chgQty(${id},1)"><i class="fas fa-plus" style="font-size:8px"></i></button>
          </div>
          <div class="ci-sb">Rp ${fmt(sb)}</div>
        </div>`;
      }).join('');
      updTotals();
    }

    function getSub() {
      return Object.values(cart).reduce((s, i) => s + i.qty * i.harga, 0);
    }

    function getTotal() {
      return getSub();
    }

    function updTotals() {
      const s = getSub();
      const minDp = Math.ceil(s * 0.5);
      $('s-sub').textContent = 'Rp ' + fmt(s);
      $('s-total').textContent = 'Rp ' + fmt(s);
      $('dp-min-show').textContent = 'Rp ' + fmt(minDp);
      $('dp-warn-min').textContent = 'Rp ' + fmt(minDp);
      calcSisa();
    }

    function calcSisa() {
      const tot = getTotal();
      const dp = parseFloat($('po-dp').value) || 0;
      const minDp = Math.ceil(tot * 0.5);
      const sisa = Math.max(tot - dp, 0);
      $('po-sisa').textContent = 'Rp ' + fmt(sisa);
      const dpInp = $('po-dp'),
        dpWarn = $('dp-warn');
      if (tot > 0 && dp < minDp) {
        dpInp.classList.add('invalid');
        dpWarn.classList.remove('hidden');
      } else {
        dpInp.classList.remove('invalid');
        dpWarn.classList.add('hidden');
      }
      updBtn();
    }

    function updBtn() {
      const tot = getTotal();
      const dp = parseFloat($('po-dp').value) || 0;
      const minDp = Math.ceil(tot * 0.5);
      $('btn-pesan').disabled = !(tot > 0 && dp >= minDp && dp <= tot);
    }

    /* ── Confirm & Submit ── */
    function confirmPesan() {
      if (OWNER) return;
      const nm = $('po-nama').value.trim() || 'Tamu';
      const hp = $('po-hp').value.trim() || '0000000000';
      const alamat = $('po-alamat').value.trim();
      const tot = getTotal();
      const dp = parseFloat($('po-dp').value) || 0;
      const met = $('po-met-dp').selectedOptions[0].text;
      $('c-body').innerHTML = `
        <div class="c-row"><span class="ck">Pemesan</span><strong>${nm}</strong></div>
        <div class="c-row"><span class="ck">No. HP</span><strong>${hp}</strong></div>
        ${alamat ? `<div class="c-row"><span class="ck">Alamat</span><strong>${alamat}</strong></div>` : ''}
        <div class="c-row"><span class="ck">Total</span><strong>Rp ${fmt(tot)}</strong></div>
        <div class="c-row"><span class="ck">DP (${met})</span><strong style="color:var(--amber)">Rp ${fmt(dp)}</strong></div>
        <div class="c-row"><span class="ck">Sisa Nanti</span><strong style="color:var(--rose)">Rp ${fmt(tot - dp)}</strong></div>
        ${member ? `<div class="c-row"><span class="ck">Member</span><strong style="color:var(--violet)">${member.nama}</strong></div>` : ''}
      `;
      openM('modal-confirm');
    }

    function doSubmit() {
      closeM('modal-confirm');
      const fd = new FormData();
      fd.append('ajax_tambah_pesanan', '1');
      fd.append('nama_pemesan', $('po-nama').value.trim());
      fd.append('no_hp', $('po-hp').value.trim());
      fd.append('alamat', $('po-alamat').value.trim());
      fd.append('catatan', $('po-catatan').value.trim());
      fd.append('id_member', $('po-member').value);
      fd.append('total', getTotal());
      fd.append('dp', parseFloat($('po-dp').value) || 0);
      fd.append('metode_dp', $('po-met-dp').value);
      fd.append('items', JSON.stringify(Object.values(cart).map(i => ({
        id_obat: i.id_obat,
        jumlah: i.qty,
        harga: i.harga
      }))));
      fetch(window.location.href, {
          method: 'POST',
          body: fd
        })
        .then(r => r.json())
        .then(d => {
          if (d.success) {
            $('sukses-sub').textContent = `Pesanan #${String(d.id_pesanan).padStart(4, '0')} berhasil disimpan.`;
            openM('modal-sukses');
          } else showErr(d.message);
        })
        .catch(() => showErr('Koneksi gagal.'));
    }

    /* ── Lunasi ── */
    function openLunasi(id, sisa, nama) {
      lnId = id;
      lnSisa = sisa;
      $('ln-amount').textContent = 'Rp ' + fmt(sisa);
      $('ln-name').textContent = `Pesanan atas nama "${nama}"`;
      $('ln-bayar').value = '';
      $('ln-kemb').classList.add('hidden');
      $('btn-ln').disabled = true;

      fetch(`${window.location.href}?ajax_cek_stok=1&id_pesanan=${id}`)
        .then(r => r.json())
        .then(d => {
          if (!d.ready) {
            const list = d.items.map(it => `<li>${it}</li>`).join('');
            showErr(`Stok tidak ready. Item berikut kurang:\n\n<ul style="text-align:left;margin:10px 0;padding-left:20px">${list}</ul>`);
            return;
          }
          openM('modal-lunasi');
        })
        .catch(() => showErr('Gagal cek stok.'));
    }

    function calcLnKemb() {
      const b = parseFloat($('ln-bayar').value) || 0;
      const k = b - lnSisa;
      if (b > 0) {
        $('ln-kemb').classList.remove('hidden');
        $('ln-kemb-val').textContent = 'Rp ' + fmt(Math.max(k, 0));
        $('ln-kemb-val').style.color = k >= 0 ? 'var(--leaf-2)' : 'var(--rose)';
      } else {
        $('ln-kemb').classList.add('hidden');
      }
      $('btn-ln').disabled = b < lnSisa;
    }

    function doLunasi() {
      const fd = new FormData();
      fd.append('ajax_lunasi', '1');
      fd.append('id_pesanan', lnId);
      fd.append('bayar', $('ln-bayar').value);
      fd.append('metode', $('ln-met').value);
      fetch(window.location.href, {
          method: 'POST',
          body: fd
        })
        .then(r => r.json())
        .then(d => {
          closeM('modal-lunasi');
          if (d.success) {
            toast('Pesanan dilunasi & masuk riwayat penjualan!');
            setTimeout(() => location.reload(), 1600);
          } else showErr(d.message);
        })
        .catch(() => showErr('Koneksi gagal.'));
    }

    /* ── Batal ── */
    function openBatal(id, dp, nama) {
      btId = id;
      $('bt-sub').textContent = `Batalkan pesanan atas nama "${nama}"?`;
      $('bt-dp').textContent = 'Rp ' + fmt(dp);
      $('bt-alasan').value = '';
      openM('modal-batal');
    }

    function doBatal() {
      const fd = new FormData();
      fd.append('ajax_batal', '1');
      fd.append('id_pesanan', btId);
      fd.append('alasan', $('bt-alasan').value.trim());
      fetch(window.location.href, {
          method: 'POST',
          body: fd
        })
        .then(r => r.json())
        .then(d => {
          closeM('modal-batal');
          if (d.success) {
            toast('Pesanan dibatalkan. DP hangus.');
            setTimeout(() => location.reload(), 1600);
          } else showErr(d.message);
        })
        .catch(() => showErr('Koneksi gagal.'));
    }

    /* ── Detail ── */
    function openDetail(id, nama) {
      $('det-title').textContent = `Detail Pesanan — ${nama}`;
      $('det-body').innerHTML = `<div style="text-align:center;padding:30px;color:var(--ink-3)"><i class="fas fa-spinner fa-spin" style="font-size:24px"></i></div>`;
      openM('modal-detail');
      fetch(`${window.location.href}?ajax_detail=1&id_pesanan=${id}`)
        .then(r => r.json())
        .then(d => {
          const its = d.items.map(it => `
            <div class="det-item">
              <div><div class="dn">${it.nama_obat}</div><div class="dq">${it.jumlah} × Rp ${fmt(it.harga)}</div></div>
              <div style="font-weight:800;color:var(--leaf-2)">Rp ${fmt(it.subtotal)}</div>
            </div>`).join('');
          const pays = d.payments.length ?
            d.payments.map(p => `
              <div class="pay-item">
                <div><div class="pk">${p.keterangan}</div><div class="pd">${p.tanggal} · ${p.metode}</div></div>
                <div style="font-weight:800;color:var(--leaf-2)">Rp ${fmt(p.jumlah)}</div>
              </div>`).join('') :
            `<div style="color:var(--ink-3);text-align:center;padding:12px;font-size:13px">Belum ada pembayaran</div>`;
          $('det-body').innerHTML = `
            <div><div class="det-sec-ttl">Item Pesanan</div>${its}</div>
            <div><div class="det-sec-ttl">Riwayat Pembayaran</div><div style="display:flex;flex-direction:column;gap:6px">${pays}</div></div>`;
        })
        .catch(() => {
          $('det-body').innerHTML = `<div style="color:var(--rose);text-align:center;padding:20px">Gagal memuat data.</div>`;
        });
    }

    /* ── Tabs ── */
    function swTab(tab, btn) {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
      if (btn) btn.classList.add('active');
      $('tab-' + tab).classList.add('active');
    }

    function goRiwayat() {
      closeM('modal-sukses');
      swTab('riwayat', document.querySelectorAll('.tab-btn')[1]);
    }

    /* ── Modals ── */
    function openM(id) {
      $(id).classList.add('open');
    }

    function closeM(id) {
      $(id).classList.remove('open');
    }
    document.querySelectorAll('.overlay').forEach(o => {
      o.addEventListener('click', e => {
        if (e.target === o) o.classList.remove('open');
      });
    });

    /* ── Toast / Error ── */
    function toast(msg, err = false) {
      const t = $('toast');
      t.innerHTML = `<i class="fas fa-${err ? 'exclamation-circle' : 'check-circle'}"></i> ${msg}`;
      t.className = 'toast show' + (err ? ' err' : '');
      setTimeout(() => t.className = 'toast', 3000);
    }

    function showErr(msg) {
      const el = $('err-msg');
      el[msg.includes('<') ? 'innerHTML' : 'textContent'] = msg;
      openM('modal-error');
    }

    /* ── Init ── */
    renderCart();
    renderGrid();
    updTotals();
  </script>
</body>

</html>