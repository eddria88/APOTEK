<?php
session_start();
require_once "../koneksi.php";

$data=json_decode(file_get_contents("php://input"),true);

$cart=$data['cart'];
$total=$data['total'];
$bayar=$data['bayar'];
$kembalian=$bayar-$total;

$username=$_SESSION['user'];
$user=mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM users WHERE username='$username'"));

$id_user=$user['id_user'];

mysqli_query($conn,"INSERT INTO penjualan
(tanggal,total,bayar,kembalian,id_user)
VALUES
(NOW(),'$total','$bayar','$kembalian','$id_user')");

$id_penjualan=mysqli_insert_id($conn);

foreach($cart as $item){

$id_obat=$item['id'];
$jumlah=$item['qty'];
$harga=$item['harga'];
$subtotal=$jumlah*$harga;

mysqli_query($conn,"INSERT INTO detail_penjualan
(id_penjualan,id_obat,jumlah,harga_jual,subtotal)
VALUES
('$id_penjualan','$id_obat','$jumlah','$harga','$subtotal')");

mysqli_query($conn,"UPDATE obat
SET stok=stok-$jumlah
WHERE id_obat='$id_obat'");

mysqli_query($conn,"INSERT INTO stok_keluar
(id_obat,tanggal,jumlah,keterangan)
VALUES
('$id_obat',NOW(),'$jumlah','Penjualan ID $id_penjualan')");

}

echo json_encode(["status"=>"ok"]);