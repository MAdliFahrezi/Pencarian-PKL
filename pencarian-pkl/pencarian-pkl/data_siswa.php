

<?php
session_start();
require_once 'koneksi.php';

// Hanya admin yang boleh mengakses halaman ini.
if (
    !isset($_SESSION['id_pengguna']) ||
    !isset($_SESSION['peran']) ||
    $_SESSION['peran'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

// Amankan output HTML.
function h($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$namaAdmin = $_SESSION['nama_lengkap'] ?? 'Administrator';

// Validasi parameter pencarian agar harus berupa teks.
$cariInput = $_GET['cari'] ?? '';
$cari = is_string($cariInput) ? trim($cariInput) : '';

$jurusanInput = $_GET['jurusan'] ?? '';
$jurusanDipilih = is_string($jurusanInput)
    ? trim($jurusanInput)
    : '';

// Ambil daftar jurusan untuk filter.
$jurusanList = $pdo->query(
    "SELECT id_jurusan, kode_jurusan, nama_jurusan
     FROM jurusan
     ORDER BY nama_jurusan"
)->fetchAll(PDO::FETCH_ASSOC);

// Statistik siswa.
$totalSiswa = (int) $pdo->query(
    "SELECT COUNT(*) FROM siswa"
)->fetchColumn();

$totalJurusan = (int) $pdo->query(
    "SELECT COUNT(DISTINCT id_jurusan)
     FROM siswa
     WHERE id_jurusan IS NOT NULL"
)->fetchColumn();

// Query daftar siswa.
$sql = "SELECT
            s.id_siswa,
            s.nis,
            s.kelas,
            u.nama_lengkap,
            u.email,
            j.kode_jurusan,
            j.nama_jurusan
        FROM siswa s
        JOIN pengguna u ON s.id_pengguna = u.id_pengguna
        LEFT JOIN jurusan j ON s.id_jurusan = j.id_jurusan
        WHERE 1=1";

$params = [];

if ($cari !== '') {
    $sql .= " AND (
        u.nama_lengkap LIKE ?
        OR u.email LIKE ?
        OR s.nis LIKE ?
        OR j.nama_jurusan LIKE ?
        OR j.kode_jurusan LIKE ?
        OR s.kelas LIKE ?
    )";

    $kata = '%' . $cari . '%';

    array_push(
        $params,
        $kata,
        $kata,
        $kata,
        $kata,
        $kata,
        $kata
    );
}

// Filter hanya jika parameter berupa angka positif.
if (
    $jurusanDipilih !== '' &&
    ctype_digit($jurusanDipilih) &&
    (int) $jurusanDipilih > 0
) {
    $sql .= " AND s.id_jurusan = ?";
    $params[] = (int) $jurusanDipilih;
}

$sql .= " ORDER BY u.nama_lengkap ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$siswaList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jumlahHasil = count($siswaList);
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Siswa | CariTempatPKL</title>

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

.filters input,
.filters select {
    padding: 11px 13px;
    border: 1px solid #d7e0ec;
    border-radius: 8px;
    font-size: 13px;
    background: white;
}

.filters input {
    flex: 1;
    min-width: 200px;
}

.filters select {
    min-width: 180px;
}

.btn {
    padding: 11px 16px;
    border: none;
    border-radius: 8px;
    background: #1d5bbf;
    color: white;
    cursor: pointer;
    font-size: 13px;
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

.avatar {
    display: inline-flex;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    align-items: center;
    justify-content: center;
    margin-right: 9px;
    background: #dbeafe;
    color: #17458a;
    font-weight: bold;
    vertical-align: middle;
}

.student-name {
    display: inline-block;
    vertical-align: middle;
    font-weight: 600;
}

.student-email {
    display: block;
    color: #64748b;
    font-size: 11px;
    margin-top: 4px;
    font-weight: normal;
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
</style>
</head>

<body>
<div class="layout">

<aside class="sidebar">
    <div class="brand">CariTempat<span>PKL</span></div>
    <div class="subtitle">Panel Administrasi</div>

    <div class="menu-title">Menu utama</div>
    <nav class="menu">
        <a href="dashboard_admin.php">▦ Dashboard</a>
        <a href="data_siswa.php" class="active">♙ Data Siswa</a>
        <a href="admin_pendaftaran.php">▤ Pendaftaran</a>
        <a href="cari_perusahaan.php">⌕ Lowongan PKL</a>
        <a href="status_pendaftaran.php">◷ Status Pendaftaran</a>
    </nav>

    <div class="menu-title">Akun</div>
    <nav class="menu">
        <a href="logout.php">⇥ Keluar</a>
    </nav>
</aside>

<main class="main">

<header class="topbar">
    <div>
        <h1>Data Siswa</h1>
        <p>Kelola dan lihat informasi siswa PKL</p>
    </div>

    <div class="admin-name">
        Admin: <?= h($namaAdmin) ?>
    </div>
</header>

<section class="content">

    <div class="page-heading">
        <h2>Daftar Siswa</h2>
        <p>Informasi siswa yang terdaftar di sistem CariTempatPKL.</p>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-label">Total Siswa Terdaftar</div>
            <div class="stat-number"><?= $totalSiswa ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Jurusan yang Memiliki Siswa</div>
            <div class="stat-number"><?= $totalJurusan ?></div>
        </div>
    </div>

    <section class="panel">

        <div class="panel-head">
            <h3>Data Seluruh Siswa</h3>
            <p>Cari siswa berdasarkan nama, NIS, email, kelas, atau jurusan.</p>
        </div>

        <form class="filters" method="GET">
            <input
                type="search"
                name="cari"
                placeholder="Cari nama, NIS, email, kelas..."
                value="<?= h($cari) ?>"
            >

            <select name="jurusan">
                <option value="">Semua jurusan</option>

                <?php foreach ($jurusanList as $jurusan): ?>
                    <option
                        value="<?= (int) $jurusan['id_jurusan'] ?>"
                        <?= $jurusanDipilih === (string) $jurusan['id_jurusan'] ? 'selected' : '' ?>
                    >
                        <?= h($jurusan['kode_jurusan'] . ' - ' . $jurusan['nama_jurusan']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="btn" type="submit">Cari</button>
            <a class="btn btn-reset" href="data_siswa.php">Reset</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Siswa</th>
                        <th>NIS</th>
                        <th>Jurusan</th>
                        <th>Kelas</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$siswaList): ?>
                    <tr>
                        <td colspan="5" class="empty">
                            Data siswa tidak ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($siswaList as $i => $siswa): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>

                            <td>
                                <span class="avatar">
                                    <?= h(function_exists('mb_substr')
    ? mb_strtoupper(mb_substr($siswa['nama_lengkap'] ?? '', 0, 1, 'UTF-8'), 'UTF-8')
    : strtoupper(substr($siswa['nama_lengkap'] ?? '', 0, 1))) ?>
                                </span>

                                <span class="student-name">
                                    <?= h($siswa['nama_lengkap']) ?>
                                    <span class="student-email">
                                        <?= h($siswa['email']) ?>
                                    </span>
                                </span>
                            </td>

                            <td><?= h($siswa['nis'] ?: '-') ?></td>

                            <td>
                                <?php if ($siswa['kode_jurusan']): ?>
                                    <span class="tag">
                                        <?= h($siswa['kode_jurusan']) ?>
                                    </span>
                                    <?= h($siswa['nama_jurusan']) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td><?= h($siswa['kelas'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="result-count">
            Menampilkan <?= $jumlahHasil ?> siswa dari
            <?= $totalSiswa ?> siswa terdaftar.
        </div>

    </section>

    <div class="footer">
        © <?= date('Y') ?> CariTempatPKL — Panel Administrasi
    </div>

</section>
</main>
</div>
</body>
</html>
