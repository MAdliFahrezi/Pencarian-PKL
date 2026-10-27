
<?php
session_start();
require_once 'koneksi.php';

if (
    !isset($_SESSION['id_pengguna'], $_SESSION['peran']) ||
    !in_array($_SESSION['peran'], ['admin', 'koordinator'], true)
) {
    header('Location: login.php');
    exit;
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$peran = $_SESSION['peran'];
$dashboard = $peran === 'koordinator'
    ? 'dashboard_koordinator.php'
    : 'dashboard_admin.php';

$statusValid = [
    '', 'menunggu', 'diproses', 'diterima', 'ditolak', 'dibatalkan'
];

$status = $_GET['status'] ?? '';
if (!is_string($status) || !in_array($status, $statusValid, true)) {
    $status = '';
}

$tanggalMulai = $_GET['tanggal_mulai'] ?? '';
$tanggalSelesai = $_GET['tanggal_selesai'] ?? '';

function tanggalValid($tanggal): bool {
    if (!is_string($tanggal) || $tanggal === '') {
        return false;
    }

    $d = DateTime::createFromFormat('!Y-m-d', $tanggal);
    return $d && $d->format('Y-m-d') === $tanggal;
}

if ($tanggalMulai !== '' && !tanggalValid($tanggalMulai)) {
    $tanggalMulai = '';
}
if ($tanggalSelesai !== '' && !tanggalValid($tanggalSelesai)) {
    $tanggalSelesai = '';
}
if (
    $tanggalMulai !== '' &&
    $tanggalSelesai !== '' &&
    $tanggalMulai > $tanggalSelesai
) {
    $temp = $tanggalMulai;
    $tanggalMulai = $tanggalSelesai;
    $tanggalSelesai = $temp;
}

$where = [];
$params = [];

if ($status !== '') {
    $where[] = 'pd.status = ?';
    $params[] = $status;
}

if ($tanggalMulai !== '') {
    $where[] = 'pd.tanggal_daftar >= ?';
    $params[] = $tanggalMulai . ' 00:00:00';
}

if ($tanggalSelesai !== '') {
    $where[] = 'pd.tanggal_daftar < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $tanggalSelesai;
}

$sql = "
    SELECT
        pd.id_pendaftaran,
        pd.tanggal_daftar,
        pd.status,
        s.nis,
        s.kelas,
        u.nama_lengkap,
        j.kode_jurusan,
        l.judul_lowongan,
        pr.nama_perusahaan
    FROM pendaftaran pd
    JOIN siswa s ON s.id_siswa = pd.id_siswa
    JOIN pengguna u ON u.id_pengguna = s.id_pengguna
    JOIN jurusan j ON j.id_jurusan = s.id_jurusan
    JOIN lowongan_pkl l ON l.id_lowongan = pd.id_lowongan
    JOIN perusahaan pr ON pr.id_perusahaan = l.id_perusahaan
";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY pd.tanggal_daftar DESC, pd.id_pendaftaran DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$laporan = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ringkasan = [
    'total' => count($laporan),
    'menunggu' => 0,
    'diproses' => 0,
    'diterima' => 0,
    'ditolak' => 0,
    'dibatalkan' => 0
];

foreach ($laporan as $row) {
    if (isset($ringkasan[$row['status']])) {
        $ringkasan[$row['status']]++;
    }
}

/* Ekspor CSV yang dapat dibuka menggunakan Microsoft Excel. */
if (isset($_GET['ekspor']) && $_GET['ekspor'] === 'csv') {
    $namaFile = 'laporan_pkl_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');

    $output = fopen('php://output', 'w');
    // BOM agar karakter UTF-8 terbaca dengan baik oleh Excel.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'No', 'Nama Siswa', 'NIS', 'Kelas', 'Jurusan',
        'Lowongan', 'Perusahaan', 'Tanggal Daftar', 'Status'
    ], ';');

    foreach ($laporan as $i => $row) {
        fputcsv($output, [
            $i + 1,
            $row['nama_lengkap'],
            $row['nis'],
            $row['kelas'],
            $row['kode_jurusan'],
            $row['judul_lowongan'],
            $row['nama_perusahaan'],
            $row['tanggal_daftar'],
            ucfirst($row['status'])
        ], ';');
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laporan PKL - CariTempatPKL</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
body{background:#f4f7fc;color:#172744}
.wrap{max-width:1200px;margin:32px auto;padding:0 20px}
.hero,.panel,.stat{background:#fff;border:1px solid #e3e9f3;border-radius:16px;padding:22px}
.hero{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-bottom:20px}
.hero h1{margin:0 0 8px;font-size:28px}
.muted{color:#71809c}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}
.stat span{color:#71809c;font-size:13px}
.stat strong{display:block;font-size:27px;margin-top:8px}
.actions,.filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.actions{margin-bottom:20px}
.btn{display:inline-block;border:0;background:#2c5ce5;color:white;padding:11px 15px;border-radius:9px;text-decoration:none;font-weight:700;cursor:pointer}
.btn.light{background:#edf2ff;color:#234bb5}
.panel{margin-bottom:20px;overflow-x:auto}
.filters input,.filters select{font:inherit;padding:10px;border:1px solid #d9e1ef;border-radius:9px;max-width:100%}
table{width:100%;border-collapse:collapse;min-width:900px}
th,td{text-align:left;padding:13px 10px;border-bottom:1px solid #e8edf5;font-size:13px}
th{color:#71809c;background:#f8faff}
.badge{display:inline-block;padding:5px 9px;border-radius:20px;background:#edf2ff;color:#234bb5;font-size:12px}
@media(max-width:800px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:450px){.stats{grid-template-columns:1fr}.wrap{padding:0 12px}.hero h1{font-size:23px}}
</style>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= e($dashboard) ?>">
        <span class="brand-icon">PKL</span>
        <span>CariTempat<span class="brand-blue">PKL</span></span>
    </a>
    <div class="user-menu">
        <strong><?= e($_SESSION['nama_lengkap'] ?? 'Pengelola PKL') ?></strong>
        <a class="logout-link" href="logout.php">Keluar</a>
    </div>
</header>

<main class="wrap">
    <section class="hero">
        <div>
            <h1>Laporan PKL</h1>
            <p class="muted">Rekapitulasi pendaftaran dan penempatan PKL siswa.</p>
        </div>
        <span class="badge"><?= $peran === 'koordinator' ? 'Koordinator PKL' : 'Admin' ?></span>
    </section>

    <section class="stats">
        <div class="stat"><span>Total pendaftaran sesuai filter</span><strong><?= $ringkasan['total'] ?></strong></div>
        <div class="stat"><span>Menunggu</span><strong><?= $ringkasan['menunggu'] ?></strong></div>
        <div class="stat"><span>Diproses</span><strong><?= $ringkasan['diproses'] ?></strong></div>
        <div class="stat"><span>Diterima</span><strong><?= $ringkasan['diterima'] ?></strong></div>
    </section>

    <nav class="actions">
        <a class="btn light" href="<?= e($dashboard) ?>">← Kembali ke Dashboard</a>
        <a class="btn" href="?<?= e(http_build_query([
            'status' => $status,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalSelesai,
            'ekspor' => 'csv'
        ])) ?>">Ekspor Excel (CSV)</a>
    </nav>

    <section class="panel">
        <h2>Filter Laporan</h2>
        <form method="GET" class="filters">
            <label>Status:
                <select name="status">
                    <option value="">Semua status</option>
                    <?php foreach ($statusValid as $s): if ($s === '') continue; ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                        <?= e(ucfirst($s)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Dari tanggal:
                <input type="date" name="tanggal_mulai" value="<?= e($tanggalMulai) ?>">
            </label>
            <label>Sampai tanggal:
                <input type="date" name="tanggal_selesai" value="<?= e($tanggalSelesai) ?>">
            </label>
            <button class="btn" type="submit">Terapkan Filter</button>
            <a class="btn light" href="laporan_pkl.php">Reset</a>
        </form>
    </section>

    <section class="panel">
        <h2>Data Laporan Pendaftaran PKL</h2>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>No.</th><th>Nama Siswa</th><th>NIS</th><th>Kelas</th>
                    <th>Jurusan</th><th>Lowongan</th><th>Perusahaan</th>
                    <th>Tanggal Daftar</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$laporan): ?>
                <tr><td colspan="9">Tidak ada data untuk filter yang dipilih.</td></tr>
            <?php else: ?>
                <?php foreach ($laporan as $i => $r): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e($r['nama_lengkap']) ?></td>
                    <td><?= e($r['nis']) ?></td>
                    <td><?= e($r['kelas']) ?></td>
                    <td><?= e($r['kode_jurusan']) ?></td>
                    <td><?= e($r['judul_lowongan']) ?></td>
                    <td><?= e($r['nama_perusahaan']) ?></td>
                    <td><?= e($r['tanggal_daftar']) ?></td>
                    <td><span class="badge"><?= e(ucfirst($r['status'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
</main>
</body>
</html>
