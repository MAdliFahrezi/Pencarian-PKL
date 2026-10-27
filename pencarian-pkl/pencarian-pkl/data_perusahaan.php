
<?php
session_start();
require_once 'koneksi.php';

if (
    !isset($_SESSION['id_pengguna']) ||
    !isset($_SESSION['peran']) ||
    !in_array($_SESSION['peran'], ['admin', 'koordinator'], true)
) {
    header('Location: login.php');
    exit;
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Pastikan token CSRF tersedia untuk formulir penghapusan.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$peran = $_SESSION['peran'];
$adalahAdmin = ($peran === 'admin');
$namaPengguna = $_SESSION['nama_lengkap'] ?? ($adalahAdmin ? 'Administrator' : 'Koordinator PKL');
$dashboardKembali = $adalahAdmin ? 'dashboard_admin.php' : 'dashboard_koordinator.php';
$labelPeran = $adalahAdmin ? 'Admin' : 'Koordinator PKL';
$cari = trim($_GET['cari'] ?? '');

$totalPerusahaan = (int) $pdo->query(
    "SELECT COUNT(*) FROM perusahaan"
)->fetchColumn();

$totalBidang = (int) $pdo->query(
    "SELECT COUNT(DISTINCT bidang_usaha)
     FROM perusahaan
     WHERE bidang_usaha IS NOT NULL
       AND bidang_usaha <> ''"
)->fetchColumn();

$sql = "SELECT
            p.id_perusahaan,
            p.nama_perusahaan,
            p.bidang_usaha,
            p.alamat,
            p.kota,
            p.telepon,
            p.email,
            p.deskripsi,
            COUNT(l.id_lowongan) AS total_lowongan
        FROM perusahaan p
        LEFT JOIN lowongan_pkl l
            ON p.id_perusahaan = l.id_perusahaan
        WHERE 1=1";

$params = [];

if ($cari !== '') {
    $sql .= " AND (
        p.nama_perusahaan LIKE ?
        OR p.bidang_usaha LIKE ?
        OR p.kota LIKE ?
        OR p.email LIKE ?
        OR p.telepon LIKE ?
        OR p.alamat LIKE ?
    )";

    $kata = '%' . $cari . '%';
    $params = [$kata, $kata, $kata, $kata, $kata, $kata];
}

$sql .= " GROUP BY
            p.id_perusahaan,
            p.nama_perusahaan,
            p.bidang_usaha,
            p.alamat,
            p.kota,
            p.telepon,
            p.email,
            p.deskripsi
          ORDER BY p.nama_perusahaan ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$perusahaanList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jumlahHasil = count($perusahaanList);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Perusahaan | CariTempatPKL</title>

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: #f4f7fb;
    color: #1e293b;
}

.layout {
    display: flex;
    min-height: 100vh;
}

.sidebar {
    width: 245px;
    flex-shrink: 0;
    background: #123b75;
    color: white;
    padding: 28px 18px;
}

.brand {
    font-size: 21px;
    font-weight: bold;
    margin-bottom: 8px;
}

.brand span {
    color: #7dd3fc;
}

.subtitle {
    font-size: 12px;
    color: #cbdcf4;
    margin-bottom: 35px;
}

.menu-title {
    font-size: 11px;
    text-transform: uppercase;
    color: #a9c1e2;
    margin: 22px 12px 10px;
    letter-spacing: 1px;
}

.menu a {
    display: block;
    text-decoration: none;
    color: #e2ecfa;
    padding: 13px 14px;
    border-radius: 9px;
    margin-bottom: 5px;
    font-size: 14px;
}

.menu a:hover,
.menu a.active {
    background: #24549a;
    color: white;
}

.main {
    flex: 1;
    min-width: 0;
}

.topbar {
    background: white;
    padding: 22px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    border-bottom: 1px solid #e5eaf1;
}

.topbar h1 {
    font-size: 23px;
}

.topbar p {
    font-size: 13px;
    color: #64748b;
    margin-top: 5px;
}

.admin-name {
    font-size: 13px;
    font-weight: bold;
    background: #eaf2ff;
    color: #17458a;
    padding: 11px 14px;
    border-radius: 10px;
}

.content {
    padding: 28px 30px;
}

.page-heading {
    margin-bottom: 22px;
}

.page-heading h2 {
    font-size: 22px;
    margin-bottom: 8px;
}

.page-heading p {
    font-size: 14px;
    color: #64748b;
}

.stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    padding: 22px;
    border-radius: 14px;
    border: 1px solid #e7edf5;
}

.stat-label {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 12px;
}

.stat-number {
    font-size: 29px;
    font-weight: bold;
    color: #153e79;
}

.panel {
    background: white;
    border-radius: 14px;
    border: 1px solid #e7edf5;
    overflow: hidden;
}

.panel-head {
    padding: 21px 23px;
    border-bottom: 1px solid #edf1f6;
}

.panel-head h3 {
    font-size: 17px;
    margin-bottom: 6px;
}

.panel-head p {
    font-size: 12px;
    color: #64748b;
}

.filters {
    padding: 18px 22px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    border-bottom: 1px solid #edf1f6;
}

.filters input {
    flex: 1;
    min-width: 200px;
    padding: 11px 13px;
    border: 1px solid #d7e0ec;
    border-radius: 8px;
    font-size: 13px;
}

.btn {
    display: inline-block;
    padding: 10px 14px;
    border: none;
    border-radius: 8px;
    background: #1d5bbf;
    color: white;
    cursor: pointer;
    font-size: 12px;
    text-decoration: none;
}

.btn:hover {
    background: #17458a;
}

.btn-reset {
    background: #e8eef7;
    color: #334155;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    white-space: nowrap;
}

th, td {
    text-align: left;
    padding: 15px 18px;
    border-bottom: 1px solid #edf1f6;
    font-size: 13px;
}

th {
    color: #64748b;
    background: #f8fafc;
    font-weight: 600;
}

tbody tr:hover {
    background: #f8fbff;
}

.company-name {
    font-weight: bold;
}

.company-detail {
    display: block;
    margin-top: 5px;
    color: #64748b;
    font-size: 11px;
}

.tag {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 7px;
    background: #eaf2ff;
    color: #17458a;
    font-size: 11px;
    font-weight: bold;
}

.lowongan-count {
    display: inline-block;
    padding: 6px 10px;
    background: #dcfce7;
    color: #166534;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
}

.empty {
    padding: 35px;
    text-align: center;
    color: #64748b;
}

.result-count {
    padding: 14px 22px;
    font-size: 12px;
    color: #64748b;
}

.footer {
    text-align: center;
    color: #94a3b8;
    font-size: 12px;
    padding: 24px 0;
}

@media (max-width: 800px) {
    .layout {
        display: block;
    }

    .sidebar {
        width: 100%;
        padding: 20px;
    }

    .subtitle {
        margin-bottom: 15px;
    }

    .menu {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .menu-title {
        display: none;
    }

    .menu a {
        padding: 10px;
        font-size: 12px;
    }

    .topbar {
        padding: 20px;
        align-items: flex-start;
        flex-direction: column;
    }

    .content {
        padding: 20px 14px;
    }

    .stats {
        gap: 10px;
    }
}

.panel-heading-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.btn-tambah {
    background: #15803d;
    font-size: 13px;
    font-weight: bold;
}

.btn-tambah:hover {
    background: #166534;
}

</style>
</head>

<body>
<div class="layout">

<aside class="sidebar">
    <div class="brand">CariTempat<span>PKL</span></div>
    <div class="subtitle"><?= h($labelPeran) ?></div>

    <div class="menu-title">Menu utama</div>
    <nav class="menu">
        <a href="<?= h($dashboardKembali) ?>">▦ Dashboard</a>
        <?php if ($adalahAdmin): ?>
            <a href="data_siswa.php">♙ Data Siswa</a>
        <?php endif; ?>
        <a href="data_perusahaan.php" class="active">▣ Data Perusahaan</a>
        <a href="admin_pendaftaran.php">▤ Pendaftaran</a>
        <a href="cari_perusahaan.php">⌕ Lowongan PKL</a>
        <?php if ($adalahAdmin): ?>
            <a href="admin_pendaftaran.php">◉ Status Pendaftaran</a>
        <?php endif; ?>
    </nav>

    <div class="menu-title">Akun</div>
    <nav class="menu">
        <a href="logout.php">⇥ Keluar</a>
    </nav>
</aside>

<main class="main">

<header class="topbar">
    <div>
        <h1>Data Perusahaan</h1>
        <p>Informasi perusahaan mitra PKL</p>
    </div>

    <div class="admin-name">
        <?= h($labelPeran) ?>: <?= h($namaPengguna) ?>
    </div>
</header>

<section class="content">

    <div class="page-heading">
        <h2>Daftar Perusahaan</h2>
        <p>Lihat perusahaan yang tersedia dalam sistem CariTempatPKL.</p>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-label">Total Perusahaan</div>
            <div class="stat-number"><?= $totalPerusahaan ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Bidang Usaha</div>
            <div class="stat-number"><?= $totalBidang ?></div>
        </div>
    </div>

    
<section class="panel">
    <div class="panel-head">
        <div class="panel-heading-row">
            <div>
                <h3>Data Seluruh Perusahaan</h3>
                <p>Cari berdasarkan nama perusahaan, bidang usaha, kota, email, atau telepon.</p>
            </div>

            <?php if ($adalahAdmin): ?>
                <a href="tambah_perusahaan.php" class="btn btn-tambah">
                    + Tambah Perusahaan
                </a>
            <?php endif; ?>
        </div>
    </div>


        <form class="filters" method="GET">
            <input
                type="search"
                name="cari"
                placeholder="Cari nama, bidang usaha, kota..."
                value="<?= h($cari) ?>"
            >

            <button class="btn" type="submit">Cari</button>
            <a class="btn btn-reset" href="data_perusahaan.php">Reset</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Perusahaan</th>
                        <th>Bidang Usaha</th>
                        <th>Kota</th>
                        <th>Telepon</th>
                        <th>Email</th>
                        <th>Lowongan</th>
                        <?php if ($adalahAdmin): ?>
                            <th>Aksi</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$perusahaanList): ?>
                    <tr>
                        <td colspan="<?= $adalahAdmin ? 8 : 7 ?>" class="empty">
                            Data perusahaan tidak ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($perusahaanList as $i => $perusahaan): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>

                            <td>
                                <span class="company-name">
                                    <?= h($perusahaan['nama_perusahaan']) ?>
                                </span>
                                <span class="company-detail">
                                    <?= h($perusahaan['alamat'] ?: 'Alamat belum tersedia') ?>
                                </span>
                            </td>

                            <td>
                                <?= h($perusahaan['bidang_usaha'] ?: '-') ?>
                            </td>

                            <td><?= h($perusahaan['kota'] ?: '-') ?></td>

                            <td><?= h($perusahaan['telepon'] ?: '-') ?></td>

                            <td><?= h($perusahaan['email'] ?: '-') ?></td>

                            <td>
                                <span class="lowongan-count">
                                    <?= (int) $perusahaan['total_lowongan'] ?> lowongan
                                </span>
                            </td>
                            <?php if ($adalahAdmin): ?>
                                <td>
                                    <a class="btn" href="edit_perusahaan.php?id=<?= (int) $perusahaan['id_perusahaan'] ?>">Edit</a>
                                    <a class="btn" style="background:#15803d" href="buat_akun_perusahaan.php?id=<?= (int) $perusahaan['id_perusahaan'] ?>">Buat Akun</a>
                                    <form method="POST" action="hapus_perusahaan.php" style="display:inline" onsubmit="return confirm('Yakin ingin menghapus perusahaan ini?');">
                                        <input type="hidden" name="id_perusahaan" value="<?= (int) $perusahaan['id_perusahaan'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                        <button type="submit" class="btn" style="background:#dc2626">Hapus</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="result-count">
            Menampilkan <?= $jumlahHasil ?> perusahaan dari
            <?= $totalPerusahaan ?> perusahaan terdaftar.
        </div>
    </section>

    <div class="footer">
        © <?= date('Y') ?> CariTempatPKL — <?= h($labelPeran) ?>
        <br><a href="<?= h($dashboardKembali) ?>" style="display:inline-block;margin-top:10px;color:#1d5bbf;text-decoration:none;font-weight:bold;">← Kembali ke Dashboard</a>
    </div>

</section>
</main>
</div>
</body>
</html>
