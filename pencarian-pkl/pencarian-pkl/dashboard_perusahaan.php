<?php
session_start();
require_once 'koneksi.php';

if (!isset($_SESSION['id_pengguna'], $_SESSION['peran']) || $_SESSION['peran'] !== 'perusahaan') {
    header('Location: login.php');
    exit;
}
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// Cari profil perusahaan yang terhubung ke akun yang sedang masuk.
$stmt = $pdo->prepare("SELECT id_perusahaan, nama_perusahaan, bidang_usaha, kota, alamat, telepon, email
                       FROM perusahaan WHERE id_pengguna = ? LIMIT 1");
$stmt->execute([(int)$_SESSION['id_pengguna']]);
$perusahaan = $stmt->fetch(PDO::FETCH_ASSOC);

$lowongan = [];
$jumlahPendaftaran = 0;
$menunggu = 0;
$notifikasi = [];
$jumlahNotifikasiBelumDibaca = 0;
if ($perusahaan) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lowongan_pkl WHERE id_perusahaan = ?");
    $stmt->execute([(int)$perusahaan['id_perusahaan']]);
    $jumlahLowongan = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pendaftaran pd JOIN lowongan_pkl l ON l.id_lowongan=pd.id_lowongan WHERE l.id_perusahaan=?");
    $stmt->execute([(int)$perusahaan['id_perusahaan']]);
    $jumlahPendaftaran = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pendaftaran pd JOIN lowongan_pkl l ON l.id_lowongan=pd.id_lowongan WHERE l.id_perusahaan=? AND pd.status IN ('menunggu','diproses')");
    $stmt->execute([(int)$perusahaan['id_perusahaan']]);
    $menunggu = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT l.id_lowongan,l.judul_lowongan,l.kuota,l.status,l.tanggal_mulai,l.tanggal_selesai,j.kode_jurusan,
                          (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.id_lowongan=l.id_lowongan AND pd.status='diterima') AS diterima
                          FROM lowongan_pkl l LEFT JOIN jurusan j ON j.id_jurusan=l.id_jurusan
                          WHERE l.id_perusahaan=? ORDER BY l.id_lowongan DESC");
    $stmt->execute([(int)$perusahaan['id_perusahaan']]);
    $lowongan = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Ambil notifikasi milik akun perusahaan yang sedang login.
    $stmt = $pdo->prepare("
        SELECT
            id_notifikasi,
            id_pendaftaran,
            judul,
            pesan,
            dibaca,
            dibuat_pada
        FROM notifikasi
        WHERE id_pengguna = ?
        ORDER BY dibuat_pada DESC, id_notifikasi DESC
        LIMIT 10
    ");
    $stmt->execute([(int) $_SESSION['id_pengguna']]);
    $notifikasi = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hitung notifikasi yang belum dibaca.
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifikasi
        WHERE id_pengguna = ?
          AND dibaca = 0
    ");
    $stmt->execute([(int) $_SESSION['id_pengguna']]);
    $jumlahNotifikasiBelumDibaca = (int) $stmt->fetchColumn();
} else {
    $jumlahLowongan = 0;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard Perusahaan</title><link rel="stylesheet" href="assets/css/style.css">
<style>
body{background:#f4f7fc;color:#172744}.dash{max-width:1150px;margin:32px auto;padding:0 20px}
.head,.panel,.stat{background:#fff;border:1px solid #e3e9f3;border-radius:16px;padding:22px}
.head{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-bottom:20px}
h1{margin:0 0 7px}.muted{color:#71809c;margin:0}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin-bottom:20px}
.stat span{color:#71809c;font-size:14px}.stat strong{display:block;font-size:30px;margin-top:8px}
.links{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}.btn{display:inline-block;text-decoration:none;background:#2c5ce5;color:white;padding:11px 15px;border-radius:9px;font-weight:700}
.btn.light{background:#edf2ff;color:#234bb5}.panel{overflow-x:auto;margin-bottom:18px}table{width:100%;border-collapse:collapse;min-width:650px}th,td{text-align:left;padding:13px 10px;border-bottom:1px solid #e8edf5}th{color:#71809c;font-size:13px}.pill{display:inline-block;padding:5px 9px;border-radius:99px;background:#edf2ff;color:#234bb5;font-size:12px}
@media(max-width:700px){.stats{grid-template-columns:1fr}}
</style></head>
<body>
<header class="topbar"><a class="brand" href="dashboard_perusahaan.php"><span class="brand-icon">PKL</span><span>CariTempat<span class="brand-blue">PKL</span></span></a>
<div class="user-menu"><strong><?= e($_SESSION['nama_lengkap'] ?? 'Perusahaan') ?></strong><a class="logout-link" href="logout.php">Keluar</a></div></header>
<main class="dash">
<section class="head"><div><h1>Dashboard Perusahaan</h1><p class="muted">Kelola lowongan dan pantau pendaftaran siswa ke perusahaanmu.</p></div><span class="pill">Perusahaan</span></section>
<?php if (!$perusahaan): ?>
<section class="panel"><h2>Profil perusahaan belum terhubung</h2><p class="muted">Akun ini belum memiliki baris pada tabel perusahaan dengan id_pengguna yang sesuai. Hubungkan akun ke profil perusahaan terlebih dahulu agar data perusahaan lain tidak ikut tampil.</p></section>
<?php else: ?>
<section class="head"><div><h2><?= e($perusahaan['nama_perusahaan']) ?></h2><p class="muted"><?= e($perusahaan['bidang_usaha']) ?> · <?= e($perusahaan['kota']) ?></p><p class="muted"><?= e($perusahaan['alamat']) ?></p></div></section>
<section class="stats">
<div class="stat"><span>Total lowongan</span><strong><?= $jumlahLowongan ?></strong></div>
<div class="stat"><span>Total pendaftaran</span><strong><?= $jumlahPendaftaran ?></strong></div>
<div class="stat"><span>Perlu ditinjau</span><strong><?= $menunggu ?></strong></div>
</section>
<nav class="links">
    <a class="btn" href="kelola_lowongan.php">
        Kelola lowongan
    </a>

    <a class="btn light" href="admin_pendaftaran.php">
        Lihat pendaftaran
    </a>

    <a class="btn light" href="#notifikasi">
        🔔 Notifikasi
        <?php if ($jumlahNotifikasiBelumDibaca > 0): ?>
            (<?= $jumlahNotifikasiBelumDibaca ?> belum dibaca)
        <?php endif; ?>
    </a>
</nav>

<section class="panel" id="notifikasi">
    <h2>
        🔔 Notifikasi
        <?php if ($jumlahNotifikasiBelumDibaca > 0): ?>
            <span class="pill">
                <?= $jumlahNotifikasiBelumDibaca ?> belum dibaca
            </span>
        <?php endif; ?>
    </h2>

    <?php if (empty($notifikasi)): ?>
        <p class="muted">Belum ada notifikasi.</p>
    <?php else: ?>
        <?php foreach ($notifikasi as $n): ?>
            <article style="
                padding: 16px;
                margin-bottom: 12px;
                border-radius: 12px;
                border: 1px solid #e3e9f3;
                background: <?= (int)$n['dibaca'] === 0 ? '#edf3ff' : '#ffffff' ?>;
            ">
                <div style="
                    display: flex;
                    justify-content: space-between;
                    gap: 12px;
                    flex-wrap: wrap;
                ">
                    <strong><?= e($n['judul']) ?></strong>

                    <?php if ((int)$n['dibaca'] === 0): ?>
                        <span class="pill">Belum dibaca</span>
                    <?php else: ?>
                        <span class="pill">Sudah dibaca</span>
                    <?php endif; ?>
                </div>

                <p style="line-height: 1.7; margin-bottom: 8px;">
                    <?= e($n['pesan']) ?>
                </p>

                <small class="muted">
                    <?= e($n['dibuat_pada']) ?>
                </small>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
</main></body></html>
