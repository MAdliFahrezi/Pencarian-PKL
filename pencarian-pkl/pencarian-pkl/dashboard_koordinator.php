<?php
session_start();
require_once 'koneksi.php';

if (!isset($_SESSION['id_pengguna'], $_SESSION['peran']) || $_SESSION['peran'] !== 'koordinator') {
    header('Location: login.php');
    exit;
}
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$totalPendaftaran = (int)$pdo->query("SELECT COUNT(*) FROM pendaftaran")->fetchColumn();
$menunggu = (int)$pdo->query("SELECT COUNT(*) FROM pendaftaran WHERE status = 'menunggu'")->fetchColumn();
$diterima = (int)$pdo->query("SELECT COUNT(*) FROM pendaftaran WHERE status = 'diterima'")->fetchColumn();
$totalSiswa = (int)$pdo->query("SELECT COUNT(*) FROM siswa")->fetchColumn();
$totalLowongan = (int)$pdo->query("SELECT COUNT(*) FROM lowongan_pkl WHERE status = 'dibuka'")->fetchColumn();

$stmt = $pdo->query("
    SELECT pd.id_pendaftaran, pd.tanggal_daftar, pd.status,
           pg.nama_lengkap, s.nis, l.judul_lowongan, pr.nama_perusahaan
    FROM pendaftaran pd
    JOIN siswa s ON s.id_siswa = pd.id_siswa
    JOIN pengguna pg ON pg.id_pengguna = s.id_pengguna
    JOIN lowongan_pkl l ON l.id_lowongan = pd.id_lowongan
    JOIN perusahaan pr ON pr.id_perusahaan = l.id_perusahaan
    ORDER BY pd.tanggal_daftar DESC, pd.id_pendaftaran DESC
    LIMIT 8
");
$terbaru = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard Koordinator PKL</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
body{background:#f4f7fc;color:#172744}.dash{max-width:1200px;margin:32px auto;padding:0 20px}
.head,.panel,.stat{background:#fff;border:1px solid #e3e9f3;border-radius:16px;padding:22px}
.head{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-bottom:20px}
h1{margin:0 0 7px}.muted{color:#71809c;margin:0}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:20px}
.stat span{color:#71809c;font-size:14px}.stat strong{display:block;font-size:30px;margin-top:8px}
.links{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}.btn{display:inline-block;text-decoration:none;background:#2c5ce5;color:white;padding:11px 15px;border-radius:9px;font-weight:700}
.btn.light{background:#edf2ff;color:#234bb5}.panel{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:700px}th,td{text-align:left;padding:13px 10px;border-bottom:1px solid #e8edf5}th{color:#71809c;font-size:13px}
.pill{display:inline-block;padding:5px 9px;border-radius:99px;background:#edf2ff;color:#234bb5;font-size:12px}
@media(max-width:800px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:450px){.stats{grid-template-columns:1fr}}
</style></head>
<body>
<header class="topbar">
<a class="brand" href="dashboard_koordinator.php"><span class="brand-icon">PKL</span><span>CariTempat<span class="brand-blue">PKL</span></span></a>
<div class="user-menu"><strong><?= e($_SESSION['nama_lengkap'] ?? 'Koordinator PKL') ?></strong><a class="logout-link" href="logout.php">Keluar</a></div>
</header>
<main class="dash">
<section class="head"><div><h1>Dashboard Koordinator PKL</h1><p class="muted">Pantau pendaftaran dan pelaksanaan PKL siswa.</p></div><span class="pill">Koordinator PKL</span></section>
<section class="stats">
<div class="stat"><span>Total siswa</span><strong><?= $totalSiswa ?></strong></div>
<div class="stat"><span>Total pendaftaran</span><strong><?= $totalPendaftaran ?></strong></div>
<div class="stat"><span>Menunggu</span><strong><?= $menunggu ?></strong></div>
<div class="stat"><span>Diterima</span><strong><?= $diterima ?></strong></div>
</section>
<nav class="links">
<a class="btn" href="admin_pendaftaran.php">Kelola pendaftaran</a>
<a class="btn light" href="laporan_pkl.php">Laporan PKL</a>
<a class="btn light" href="monitoring_jurnal.php">Monitoring jurnal</a>
<a class="btn light" href="data_perusahaan.php">Data perusahaan</a>
<a class="btn light" href="kelola_lowongan.php">Kelola lowongan</a>
</nav>
<section class="panel"><h2>Pendaftaran terbaru</h2>
<?php if (!$terbaru): ?><p class="muted">Belum ada pendaftaran.</p><?php else: ?>
<table><thead><tr><th>Siswa</th><th>NIS</th><th>Lowongan</th><th>Perusahaan</th><th>Tanggal</th><th>Status</th></tr></thead><tbody>
<?php foreach ($terbaru as $r): ?><tr>
<td><?= e($r['nama_lengkap']) ?></td><td><?= e($r['nis']) ?></td><td><?= e($r['judul_lowongan']) ?></td><td><?= e($r['nama_perusahaan']) ?></td><td><?= e($r['tanggal_daftar']) ?></td><td><span class="pill"><?= e(ucfirst($r['status'])) ?></span></td>
</tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<p class="muted" style="margin-top:15px">Lowongan yang sedang dibuka: <?= $totalLowongan ?></p>
</section>
</main></body></html>
