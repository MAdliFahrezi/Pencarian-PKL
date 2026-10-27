<?php
session_start();
require_once 'koneksi.php';

if (!isset($_SESSION['id_pengguna'], $_SESSION['peran']) || $_SESSION['peran'] !== 'siswa') {
    header('Location: login.php');
    exit;
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$idPengguna = (int) $_SESSION['id_pengguna'];

if (empty($_SESSION['csrf_notifikasi'])) {
    $_SESSION['csrf_notifikasi'] = bin2hex(random_bytes(32));
}

$pesan = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_notifikasi'], $token)) {
        $pesan = 'Permintaan tidak valid. Muat ulang halaman dan coba lagi.';
    } else {
        $aksi = $_POST['aksi'] ?? '';
        if ($aksi === 'semua_dibaca') {
            $stmt = $pdo->prepare('UPDATE notifikasi SET dibaca = 1 WHERE id_pengguna = ?');
            $stmt->execute([$idPengguna]);
            header('Location: notifikasi.php?berhasil=dibaca');
            exit;
        } elseif ($aksi === 'baca') {
            $id = filter_input(INPUT_POST, 'id_notifikasi', FILTER_VALIDATE_INT);
            if ($id) {
                $stmt = $pdo->prepare('UPDATE notifikasi SET dibaca = 1 WHERE id_notifikasi = ? AND id_pengguna = ?');
                $stmt->execute([$id, $idPengguna]);
                $stmt = $pdo->prepare('SELECT id_pendaftaran FROM notifikasi WHERE id_notifikasi = ? AND id_pengguna = ?');
                $stmt->execute([$id, $idPengguna]);
                $idPendaftaran = $stmt->fetchColumn();
                if ($idPendaftaran) {
                    header('Location: riwayat_pendaftaran.php');
                    exit;
                }
            }
            header('Location: notifikasi.php');
            exit;
        }
    }
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM notifikasi WHERE id_pengguna = ? AND dibaca = 0');
$stmt->execute([$idPengguna]);
$belumDibaca = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT id_notifikasi, id_pendaftaran, judul, pesan, dibaca, dibuat_pada
                       FROM notifikasi WHERE id_pengguna = ?
                       ORDER BY dibuat_pada DESC, id_notifikasi DESC');
$stmt->execute([$idPengguna]);
$daftar = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notifikasi - CariTempatPKL</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
body{background:#f4f7fd;color:#172744}.notif-wrap{max-width:900px;margin:32px auto;padding:0 18px}
.notif-head,.notif-item{background:#fff;border:1px solid #e3e9f3;border-radius:16px;padding:22px;margin-bottom:14px}
.notif-head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}
.notif-head h1{margin:0 0 6px}.muted{color:#71809c;margin:0}
.notif-item{display:flex;gap:14px;align-items:flex-start}.notif-item.unread{border-left:5px solid #2c5ce5;background:#fafdff}
.dot{width:12px;height:12px;flex:none;border-radius:50%;background:#2c5ce5;margin-top:6px}
.notif-item.read .dot{background:#cbd5e1}.notif-content{flex:1}.notif-content h2{font-size:17px;margin:0 0 8px}
.notif-content p{margin:0 0 10px;line-height:1.6}.date{font-size:12px;color:#71809c}
.btn{display:inline-block;background:#2c5ce5;color:white;text-decoration:none;border:0;border-radius:9px;padding:10px 14px;font-weight:700;cursor:pointer}
.btn-light{background:#edf2ff;color:#234bb5}.empty{background:white;padding:35px;text-align:center;border-radius:16px}
.notice{padding:12px;background:#e2f8ea;color:#18723a;border-radius:10px;margin-bottom:14px}
@media(max-width:600px){.notif-wrap{padding:0 12px}.notif-item{padding:16px}.notif-head{align-items:flex-start}}
</style>
</head>
<body>
<header class="topbar">
  <a class="brand" href="cari_perusahaan.php"><span class="brand-icon">PKL</span><span>CariTempat<span class="brand-blue">PKL</span></span></a>
  <div class="user-menu">
    <a href="cari_perusahaan.php">Cari Lowongan</a>
    <a href="riwayat_pendaftaran.php">Riwayat Pendaftaran</a>
    <a class="logout-link" href="logout.php">Keluar</a>
  </div>
</header>
<main class="notif-wrap">
  <section class="notif-head">
    <div><h1>🔔 Notifikasi</h1><p class="muted"><?= $belumDibaca ?> notifikasi belum dibaca</p></div>
    <?php if ($belumDibaca > 0): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_notifikasi']) ?>">
      <input type="hidden" name="aksi" value="semua_dibaca">
      <button class="btn btn-light" type="submit">Tandai semua dibaca</button>
    </form>
    <?php endif; ?>
  </section>
  <?php if (($_GET['berhasil'] ?? '') === 'dibaca'): ?><div class="notice">Semua notifikasi telah ditandai dibaca.</div><?php endif; ?>
  <?php if ($pesan !== ''): ?><div class="notice"><?= e($pesan) ?></div><?php endif; ?>
  <?php if (!$daftar): ?>
    <div class="empty"><h2>Belum ada notifikasi</h2><p class="muted">Perubahan status pendaftaran akan muncul di sini.</p></div>
  <?php else: foreach ($daftar as $n): ?>
    <article class="notif-item <?= (int)$n['dibaca'] === 0 ? 'unread' : 'read' ?>">
      <span class="dot"></span>
      <div class="notif-content">
        <h2><?= e($n['judul']) ?></h2>
        <p><?= nl2br(e($n['pesan'])) ?></p>
        <div class="date"><?= e(date('d-m-Y H:i', strtotime($n['dibuat_pada']))) ?></div>
        <?php if (!empty($n['id_pendaftaran'])): ?>
        <form method="post" style="margin-top:12px">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_notifikasi']) ?>">
          <input type="hidden" name="aksi" value="baca">
          <input type="hidden" name="id_notifikasi" value="<?= (int)$n['id_notifikasi'] ?>">
          <button class="btn" type="submit">Lihat riwayat pendaftaran →</button>
        </form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; endif; ?>
</main>
</body>
</html>
