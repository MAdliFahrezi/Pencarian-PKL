<?php
session_start();
require_once 'koneksi.php';

if (!isset($_SESSION['id_pengguna'], $_SESSION['peran']) ||
    !in_array($_SESSION['peran'], ['admin', 'perusahaan'], true)) {
    header('Location: login.php');
    exit;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$peran = $_SESSION['peran'];
$idPengguna = (int)$_SESSION['id_pengguna'];
$namaPengguna = $_SESSION['nama_lengkap'] ?? ($peran === 'admin' ? 'Administrator' : 'Perusahaan');
$isAdmin = $peran === 'admin';
$error = '';
$sukses = '';
$pesan = $_GET['pesan'] ?? '';

$pesanSukses = ['hapus_lowongan_berhasil' => 'Lowongan berhasil dihapus.'];
$pesanError = [
    'lowongan_memiliki_pendaftaran' => 'Lowongan tidak dapat dihapus karena sudah memiliki pendaftaran siswa.',
    'lowongan_tidak_ditemukan' => 'Lowongan tidak ditemukan atau bukan milik akun ini.',
    'id_tidak_valid' => 'ID lowongan tidak valid.',
    'gagal_hapus_lowongan' => 'Lowongan gagal dihapus. Periksa hubungan data pada database.'
];
if (isset($pesanSukses[$pesan])) $sukses = $pesanSukses[$pesan];
elseif (isset($pesanError[$pesan])) $error = $pesanError[$pesan];

$idPerusahaanAkun = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare("SELECT id_perusahaan FROM perusahaan WHERE id_pengguna = ? LIMIT 1");
    $stmt->execute([$idPengguna]);
    $idPerusahaanAkun = $stmt->fetchColumn();
    if (!$idPerusahaanAkun) {
        http_response_code(403);
        exit('Akun perusahaan belum terhubung ke data perusahaan. Hubungi administrator.');
    }
    $idPerusahaanAkun = (int)$idPerusahaanAkun;
}

// Proteksi CSRF sederhana untuk semua aksi yang mengubah data.
if (empty($_SESSION['csrf_kelola_lowongan'])) {
    $_SESSION['csrf_kelola_lowongan'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_kelola_lowongan'];

function tanggalValid(string $tanggal): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $tanggal);

    return $date !== false
        && $date->format('Y-m-d') === $tanggal;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenPOST = $_POST['csrf_token'] ?? '';

    if (
        !is_string($tokenPOST) ||
        !hash_equals($csrf, $tokenPOST)
    ) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman.');
    }

    $aksiInput = $_POST['aksi'] ?? '';

    if (!is_string($aksiInput)) {
        http_response_code(400);
        exit('Aksi tidak valid.');
    }

    $aksi = $aksiInput;

    try {
        if ($aksi === 'tambah') {
            $idPerusahaanInput = $_POST['id_perusahaan'] ?? '';
            $idJurusanInput = $_POST['id_jurusan'] ?? '';
            $judulInput = $_POST['judul_lowongan'] ?? '';
            $kuotaInput = $_POST['kuota'] ?? '';
            $persyaratanInput = $_POST['persyaratan'] ?? '';
            $mulaiInput = $_POST['tanggal_mulai'] ?? '';
            $selesaiInput = $_POST['tanggal_selesai'] ?? '';

            foreach ([
                $idPerusahaanInput,
                $idJurusanInput,
                $judulInput,
                $kuotaInput,
                $persyaratanInput,
                $mulaiInput,
                $selesaiInput
            ] as $input) {
                if (!is_string($input)) {
                    throw new Exception('Format data yang dikirim tidak valid.');
                }
            }

            $idPerusahaan = $isAdmin
                ? filter_var($idPerusahaanInput, FILTER_VALIDATE_INT)
                : $idPerusahaanAkun;

            $idJurusan = filter_var(
                $idJurusanInput,
                FILTER_VALIDATE_INT
            );

            $kuota = filter_var(
                $kuotaInput,
                FILTER_VALIDATE_INT
            );

            $judul = trim($judulInput);
            $persyaratan = trim($persyaratanInput);
            $mulai = $mulaiInput;
            $selesai = $selesaiInput;

            if (
                $idPerusahaan === false ||
                $idPerusahaan === null ||
                $idPerusahaan <= 0 ||
                $idJurusan === false ||
                $idJurusan === null ||
                $idJurusan <= 0 ||
                $kuota === false ||
                $kuota === null ||
                $kuota <= 0
            ) {
                throw new Exception('ID perusahaan, jurusan, atau kuota tidak valid.');
            }

            if (
                $judul === '' ||
                strlen($judul) > 150 ||
                strlen($persyaratan) > 10000
            ) {
                throw new Exception(
                    'Judul wajib diisi maksimal 150 byte dan persyaratan maksimal 10.000 byte.'
                );
            }

            if (
                !tanggalValid($mulai) ||
                !tanggalValid($selesai) ||
                $selesai < $mulai
            ) {
                throw new Exception('Tanggal lowongan tidak valid.');
            }

            // Pastikan perusahaan tersedia.
            $cek = $pdo->prepare(
                "SELECT COUNT(*) FROM perusahaan WHERE id_perusahaan = ?"
            );
            $cek->execute([$idPerusahaan]);

            if (!(int) $cek->fetchColumn()) {
                throw new Exception('Perusahaan tidak ditemukan.');
            }

            // Pastikan jurusan tersedia.
            $cek = $pdo->prepare(
                "SELECT COUNT(*) FROM jurusan WHERE id_jurusan = ?"
            );
            $cek->execute([$idJurusan]);

            if (!(int) $cek->fetchColumn()) {
                throw new Exception('Jurusan tidak ditemukan.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO lowongan_pkl (
                    id_perusahaan,
                    id_jurusan,
                    judul_lowongan,
                    kuota,
                    persyaratan,
                    tanggal_mulai,
                    tanggal_selesai,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'dibuka')
            ");

            $stmt->execute([
                $idPerusahaan,
                $idJurusan,
                $judul,
                $kuota,
                $persyaratan,
                $mulai,
                $selesai
            ]);

            $sukses = 'Lowongan baru berhasil ditambahkan.';

        } elseif ($aksi === 'ubah') {
            $idInput = $_POST['id_lowongan'] ?? '';
            $kuotaInput = $_POST['kuota'] ?? '';
            $persyaratanInput = $_POST['persyaratan'] ?? '';
            $mulaiInput = $_POST['tanggal_mulai'] ?? '';
            $selesaiInput = $_POST['tanggal_selesai'] ?? '';

            foreach ([
                $idInput,
                $kuotaInput,
                $persyaratanInput,
                $mulaiInput,
                $selesaiInput
            ] as $input) {
                if (!is_string($input)) {
                    throw new Exception('Format data perubahan tidak valid.');
                }
            }

            $id = filter_var($idInput, FILTER_VALIDATE_INT);
            $kuota = filter_var($kuotaInput, FILTER_VALIDATE_INT);
            $persyaratan = trim($persyaratanInput);
            $mulai = $mulaiInput;
            $selesai = $selesaiInput;

            if (
                $id === false ||
                $id <= 0 ||
                $kuota === false ||
                $kuota <= 0
            ) {
                throw new Exception('ID lowongan atau kuota tidak valid.');
            }

            if (strlen($persyaratan) > 10000) {
                throw new Exception('Persyaratan terlalu panjang.');
            }

            if (
                !tanggalValid($mulai) ||
                !tanggalValid($selesai) ||
                $selesai < $mulai
            ) {
                throw new Exception('Tanggal lowongan tidak valid.');
            }

            // Pastikan lowongan memang ada dan dapat dikelola akun ini.
            $sqlCek = "
                SELECT id_lowongan
                FROM lowongan_pkl
                WHERE id_lowongan = ?
            ";
            $paramsCek = [$id];

            if (!$isAdmin) {
                $sqlCek .= " AND id_perusahaan = ?";
                $paramsCek[] = $idPerusahaanAkun;
            }

            $sqlCek .= " LIMIT 1";

            $cek = $pdo->prepare($sqlCek);
            $cek->execute($paramsCek);

            if (!$cek->fetchColumn()) {
                throw new Exception(
                    'Lowongan tidak ditemukan atau bukan milik akun ini.'
                );
            }

            // Kuota tidak boleh lebih kecil daripada siswa yang sudah diterima.
            $cek = $pdo->prepare("
                SELECT COUNT(*)
                FROM pendaftaran
                WHERE id_lowongan = ?
                  AND status = 'diterima'
            ");
            $cek->execute([$id]);

            $jumlahDiterima = (int) $cek->fetchColumn();

            if ($kuota < $jumlahDiterima) {
                throw new Exception(
                    'Kuota tidak boleh lebih kecil daripada jumlah siswa yang sudah diterima (' .
                    $jumlahDiterima . ' siswa).'
                );
            }

            $sql = "
                UPDATE lowongan_pkl
                SET kuota = ?,
                    persyaratan = ?,
                    tanggal_mulai = ?,
                    tanggal_selesai = ?
                WHERE id_lowongan = ?
            ";

            $params = [
                $kuota,
                $persyaratan,
                $mulai,
                $selesai,
                $id
            ];

            if (!$isAdmin) {
                $sql .= " AND id_perusahaan = ?";
                $params[] = $idPerusahaanAkun;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $sukses = 'Informasi lowongan berhasil diperbarui.';

        } elseif ($aksi === 'status') {
            $idInput = $_POST['id_lowongan'] ?? '';
            $statusInput = $_POST['status'] ?? '';

            if (
                !is_string($idInput) ||
                !is_string($statusInput)
            ) {
                throw new Exception('Format perubahan status tidak valid.');
            }

            $id = filter_var($idInput, FILTER_VALIDATE_INT);
            $status = $statusInput;

            if (
                $id === false ||
                $id <= 0 ||
                !in_array($status, ['dibuka', 'ditutup'], true)
            ) {
                throw new Exception('Perubahan status tidak valid.');
            }

            $sql = "
                UPDATE lowongan_pkl
                SET status = ?
                WHERE id_lowongan = ?
            ";

            $params = [$status, $id];

            if (!$isAdmin) {
                $sql .= " AND id_perusahaan = ?";
                $params[] = $idPerusahaanAkun;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // rowCount() bisa nol jika status tidak berubah.
            $sqlCek = "
                SELECT COUNT(*)
                FROM lowongan_pkl
                WHERE id_lowongan = ?
            ";

            $paramsCek = [$id];

            if (!$isAdmin) {
                $sqlCek .= " AND id_perusahaan = ?";
                $paramsCek[] = $idPerusahaanAkun;
            }

            $cek = $pdo->prepare($sqlCek);
            $cek->execute($paramsCek);

            if (!(int) $cek->fetchColumn()) {
                throw new Exception(
                    'Lowongan tidak ditemukan atau bukan milik akun ini.'
                );
            }

            $sukses = 'Status lowongan berhasil diperbarui.';

        } else {
            throw new Exception('Aksi tidak dikenali.');
        }

    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            error_log(
                'Kelola lowongan: ' . $e->getMessage()
            );

            $error = 'Operasi database gagal. Periksa data yang dimasukkan.';
        } else {
            $error = $e->getMessage();
        }
    }
}

if ($isAdmin) {
    $perusahaanStmt = $pdo->query("SELECT id_perusahaan, nama_perusahaan FROM perusahaan ORDER BY nama_perusahaan");
    $perusahaanList = $perusahaanStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $perusahaanStmt = $pdo->prepare("SELECT id_perusahaan, nama_perusahaan FROM perusahaan WHERE id_perusahaan = ?");
    $perusahaanStmt->execute([$idPerusahaanAkun]);
    $perusahaanList = $perusahaanStmt->fetchAll(PDO::FETCH_ASSOC);
}
$jurusanList = $pdo->query("SELECT id_jurusan, kode_jurusan, nama_jurusan FROM jurusan ORDER BY nama_jurusan")->fetchAll(PDO::FETCH_ASSOC);
$cari = trim($_GET['cari'] ?? '');

$sql = "SELECT l.id_lowongan, l.judul_lowongan, l.kuota, l.persyaratan, l.tanggal_mulai,
               l.tanggal_selesai, l.status, p.nama_perusahaan, j.kode_jurusan, j.nama_jurusan
        FROM lowongan_pkl l
        JOIN perusahaan p ON l.id_perusahaan = p.id_perusahaan
        JOIN jurusan j ON l.id_jurusan = j.id_jurusan WHERE 1=1";
$params = [];
if (!$isAdmin) { $sql .= " AND l.id_perusahaan = ?"; $params[] = $idPerusahaanAkun; }
if ($cari !== '') {
    $sql .= " AND (l.judul_lowongan LIKE ? OR p.nama_perusahaan LIKE ? OR j.nama_jurusan LIKE ?)";
    $kata = '%' . $cari . '%';
    array_push($params, $kata, $kata, $kata);
}
$sql .= " ORDER BY l.id_lowongan DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lowonganList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statSql = "SELECT COUNT(*) total,
    SUM(status = 'dibuka') dibuka, SUM(status = 'ditutup') ditutup FROM lowongan_pkl";
$statParams = [];
if (!$isAdmin) { $statSql .= " WHERE id_perusahaan = ?"; $statParams[] = $idPerusahaanAkun; }
$statStmt = $pdo->prepare($statSql);
$statStmt->execute($statParams);
$stats = $statStmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0, 'dibuka'=>0, 'ditutup'=>0];
$totalLowongan = (int)($stats['total'] ?? 0);
$totalDibuka = (int)($stats['dibuka'] ?? 0);
$totalDitutup = (int)($stats['ditutup'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kelola Lowongan | CariTempatPKL</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:Arial,sans-serif;background:#f4f7fb;color:#1e293b}
.layout{display:flex;min-height:100vh}.sidebar{width:245px;flex-shrink:0;background:#123b75;color:white;padding:28px 18px}
.brand{font-size:21px;font-weight:bold;margin-bottom:8px}.brand span{color:#7dd3fc}.subtitle{font-size:12px;color:#cbdcf4;margin-bottom:35px}
.menu-title{font-size:11px;text-transform:uppercase;color:#a9c1e2;margin:22px 12px 10px;letter-spacing:1px}
.menu a{display:block;text-decoration:none;color:#e2ecfa;padding:13px 14px;border-radius:9px;margin-bottom:5px;font-size:14px}
.menu a:hover,.menu a.active{background:#24549a;color:white}.main{flex:1;min-width:0}
.topbar{background:white;padding:22px 30px;display:flex;justify-content:space-between;align-items:center;gap:15px;border-bottom:1px solid #e5eaf1}
.topbar h1{font-size:23px}.topbar p{font-size:13px;color:#64748b;margin-top:5px}.admin-name{font-size:13px;font-weight:bold;background:#eaf2ff;color:#17458a;padding:11px 14px;border-radius:10px}
.content{padding:28px 30px}.heading{margin-bottom:22px}.heading h2{font-size:22px;margin-bottom:8px}.heading p{font-size:14px;color:#64748b}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:24px}.stat,.panel{background:white;border:1px solid #e7edf5;border-radius:14px}
.stat{padding:20px}.stat-label{font-size:13px;color:#64748b;margin-bottom:12px}.stat-number{font-size:28px;font-weight:bold;color:#153e79}
.panel{overflow:hidden;margin-bottom:22px}.panel-head{padding:20px 22px;border-bottom:1px solid #edf1f6}.panel-head h3{font-size:17px;margin-bottom:6px}.panel-head p{font-size:12px;color:#64748b}
.form-grid{padding:22px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.field{display:flex;flex-direction:column;gap:7px}
.field.full{grid-column:1/-1}.field label{font-size:13px;font-weight:bold;color:#334155}
.field input,.field select,.field textarea{width:100%;padding:11px 12px;border:1px solid #d7e0ec;border-radius:8px;font:13px Arial;background:white}
.field textarea{min-height:85px;resize:vertical}.btn{border:0;border-radius:8px;padding:10px 14px;font-size:12px;cursor:pointer;text-decoration:none;display:inline-block}
.btn-primary{background:#1d5bbf;color:white}.btn-light{background:#e8eef7;color:#334155}.btn-green{background:#dcfce7;color:#166534}.btn-red{background:#fee2e2;color:#991b1b}
.filters{display:flex;gap:10px;padding:18px 22px;border-bottom:1px solid #edf1f6;flex-wrap:wrap}.filters input{flex:1;min-width:200px;padding:11px 13px;border:1px solid #d7e0ec;border-radius:8px;font-size:13px}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{text-align:left;padding:14px 16px;border-bottom:1px solid #edf1f6;font-size:12px;vertical-align:top}
th{background:#f8fafc;color:#64748b;font-weight:600}.status{display:inline-block;padding:6px 9px;border-radius:20px;font-size:11px;font-weight:bold}
.status.dibuka{background:#dcfce7;color:#166534}.status.ditutup{background:#fee2e2;color:#991b1b}.actions{display:flex;gap:6px;align-items:center}
.inline-form{display:inline-flex;gap:6px;align-items:center}.inline-form input{width:72px;padding:7px;border:1px solid #d7e0ec;border-radius:6px}
.alert{padding:13px 16px;border-radius:9px;margin-bottom:18px;font-size:13px}.alert.success{background:#dcfce7;color:#166534}.alert.error{background:#fee2e2;color:#991b1b}
.empty{padding:30px;text-align:center;color:#64748b}.footer{text-align:center;color:#94a3b8;font-size:12px;padding:24px 0}
@media(max-width:950px){.stats{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}}
@media(max-width:800px){.layout{display:block}.sidebar{width:100%;padding:20px}.subtitle{margin-bottom:15px}.menu{display:flex;flex-wrap:wrap;gap:5px}.menu-title{display:none}.menu a{padding:10px;font-size:12px}.topbar{padding:20px;align-items:flex-start;flex-direction:column}.content{padding:20px 14px}}
</style>
</head>
<body><div class="layout">
<aside class="sidebar">
<div class="brand">CariTempat<span>PKL</span></div><div class="subtitle"><?= $isAdmin ? 'Panel Administrasi' : 'Panel Perusahaan' ?></div>
<div class="menu-title">Menu utama</div><nav class="menu">
<?php if ($isAdmin): ?>
<a href="dashboard_admin.php">▦ Dashboard</a><a href="data_siswa.php">♙ Data Siswa</a><a href="data_perusahaan.php">▣ Data Perusahaan</a>
<?php else: ?>
<a href="dashboard_perusahaan.php">▦ Dashboard Perusahaan</a>
<?php endif; ?>
<a href="kelola_lowongan.php" class="active">⌕ Kelola Lowongan</a>
<?php if ($isAdmin): ?><a href="admin_pendaftaran.php">▤ Pendaftaran</a><a href="status_pendaftaran.php">◷ Status Pendaftaran</a><?php else: ?><a href="admin_pendaftaran.php">▤ Pendaftaran Masuk</a><?php endif; ?>
</nav><div class="menu-title">Akun</div><nav class="menu"><a href="logout.php">⇥ Keluar</a></nav>
</aside>
<main class="main"><header class="topbar"><div><h1>Kelola Lowongan PKL</h1><p><?= $isAdmin ? 'Tambah dan atur lowongan seluruh perusahaan.' : 'Kelola lowongan khusus perusahaan Anda.' ?></p></div>
<div class="admin-name"><?= $isAdmin ? 'Admin: ' : 'Perusahaan: ' ?><?= h($namaPengguna) ?></div></header>
<section class="content"><div class="heading"><h2>Manajemen Lowongan</h2><p>Atur informasi lowongan yang tersedia di sistem.</p></div>
<?php if ($sukses !== ''): ?><div class="alert success"><?= h($sukses) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
<div class="stats"><div class="stat"><div class="stat-label">Total Lowongan</div><div class="stat-number"><?= $totalLowongan ?></div></div><div class="stat"><div class="stat-label">Lowongan Dibuka</div><div class="stat-number"><?= $totalDibuka ?></div></div><div class="stat"><div class="stat-label">Lowongan Ditutup</div><div class="stat-number"><?= $totalDitutup ?></div></div></div>
<section class="panel"><div class="panel-head"><h3>Tambah Lowongan Baru</h3><p>Lengkapi data berikut untuk menerbitkan lowongan PKL.</p></div>
<?php if (!$perusahaanList || !$jurusanList): ?><div class="empty">Tambahkan data perusahaan dan jurusan terlebih dahulu.</div><?php else: ?>
<form method="POST" class="form-grid"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="aksi" value="tambah">
<?php if ($isAdmin): ?><div class="field"><label>Perusahaan *</label><select name="id_perusahaan" required><option value="">Pilih perusahaan</option><?php foreach ($perusahaanList as $p): ?><option value="<?= (int)$p['id_perusahaan'] ?>"><?= h($p['nama_perusahaan']) ?></option><?php endforeach; ?></select></div><?php else: ?><div class="field"><label>Perusahaan</label><input value="<?= h($perusahaanList[0]['nama_perusahaan']) ?>" readonly></div><?php endif; ?>
<div class="field"><label>Jurusan yang Dibutuhkan *</label><select name="id_jurusan" required><option value="">Pilih jurusan</option><?php foreach ($jurusanList as $j): ?><option value="<?= (int)$j['id_jurusan'] ?>"><?= h($j['kode_jurusan'].' - '.$j['nama_jurusan']) ?></option><?php endforeach; ?></select></div>
<div class="field full"><label>Judul Lowongan *</label><input type="text" name="judul_lowongan" maxlength="200" required placeholder="Contoh: PKL Pengembangan Web"></div>
<div class="field"><label>Kuota Siswa *</label><input type="number" name="kuota" min="1" required value="1"></div>
<div class="field"><label>Tanggal Mulai *</label><input type="date" name="tanggal_mulai" required></div>
<div class="field"><label>Tanggal Selesai *</label><input type="date" name="tanggal_selesai" required></div>
<div class="field full"><label>Persyaratan</label><textarea name="persyaratan" placeholder="Tuliskan persyaratan siswa..."></textarea></div>
<div class="field full"><button class="btn btn-primary" type="submit">+ Tambahkan Lowongan</button></div></form><?php endif; ?></section>
<section class="panel"><div class="panel-head"><h3>Daftar Lowongan</h3><p><?= $isAdmin ? 'Seluruh lowongan dalam sistem.' : 'Hanya lowongan milik perusahaan Anda.' ?></p></div>
<form method="GET" class="filters"><input type="search" name="cari" value="<?= h($cari) ?>" placeholder="Cari judul, perusahaan, atau jurusan..."><button class="btn btn-primary" type="submit">Cari</button><a class="btn btn-light" href="kelola_lowongan.php">Reset</a></form>
<div class="table-wrap"><table><thead><tr><th>Lowongan</th><th>Perusahaan</th><th>Jurusan</th><th>Kuota & Periode</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php if (!$lowonganList): ?><tr><td colspan="6" class="empty">Belum ada lowongan yang cocok.</td></tr><?php else: foreach ($lowonganList as $l): ?>
<tr><td><strong><?= h($l['judul_lowongan']) ?></strong><br><small><?= h($l['persyaratan'] ?: 'Tidak ada persyaratan khusus') ?></small></td><td><?= h($l['nama_perusahaan']) ?></td><td><?= h($l['kode_jurusan'].' - '.$l['nama_jurusan']) ?></td>
<td><?= (int)$l['kuota'] ?> siswa<br><small><?= h($l['tanggal_mulai']) ?> s.d. <?= h($l['tanggal_selesai']) ?></small></td><td><span class="status <?= h($l['status']) ?>"><?= h(ucfirst($l['status'])) ?></span></td>
<td><a class="btn btn-primary" href="edit_lowongan.php?id=<?= (int)$l['id_lowongan'] ?>">Edit</a>
<form method="POST" class="inline-form" style="margin-top:7px"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="aksi" value="ubah"><input type="hidden" name="id_lowongan" value="<?= (int)$l['id_lowongan'] ?>"><input type="number" name="kuota" min="1" required value="<?= (int)$l['kuota'] ?>" title="Kuota baru"><input type="hidden" name="persyaratan" value="<?= h($l['persyaratan']) ?>"><input type="hidden" name="tanggal_mulai" value="<?= h($l['tanggal_mulai']) ?>"><input type="hidden" name="tanggal_selesai" value="<?= h($l['tanggal_selesai']) ?>"><button class="btn btn-primary" type="submit">Simpan</button></form>
<form method="POST" style="margin-top:7px"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="aksi" value="status"><input type="hidden" name="id_lowongan" value="<?= (int)$l['id_lowongan'] ?>"><?php if ($l['status']==='dibuka'): ?><input type="hidden" name="status" value="ditutup"><button class="btn btn-red" type="submit">Tutup Lowongan</button><?php else: ?><input type="hidden" name="status" value="dibuka"><button class="btn btn-green" type="submit">Buka Lowongan</button><?php endif; ?></form>
<?php if ($isAdmin): ?><form method="POST" action="hapus_lowongan.php" style="margin-top:7px" onsubmit="return confirm('Yakin ingin menghapus lowongan ini? Tindakan ini tidak dapat dibatalkan.');"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="id_lowongan" value="<?= (int)$l['id_lowongan'] ?>"><button class="btn btn-red" type="submit">Hapus Lowongan</button></form><?php endif; ?>
</td></tr><?php endforeach; endif; ?></tbody></table></div></section>
<div class="footer">© <?= date('Y') ?> CariTempatPKL — Panel <?= $isAdmin ? 'Administrasi' : 'Perusahaan' ?></div>
</section></main></div></body></html>
