
<?php
session_start();
require_once 'koneksi.php';

// Pastikan hanya siswa yang sudah login dapat mengakses halaman ini.
if (
    !isset($_SESSION['id_pengguna'], $_SESSION['id_siswa'], $_SESSION['peran']) ||
    $_SESSION['peran'] !== 'siswa'
) {
    header('Location: login.php');
    exit;
}

$idPengguna = filter_var(
    $_SESSION['id_pengguna'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$idSiswa = filter_var(
    $_SESSION['id_siswa'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($idPengguna === false || $idSiswa === false) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$namaSiswa = $_SESSION['nama_lengkap'] ?? 'Siswa';

// Verifikasi bahwa identitas siswa sesuai dengan akun yang login.
$stmt = $pdo->prepare("
    SELECT s.id_siswa, p.nama_lengkap
    FROM siswa s
    JOIN pengguna p ON p.id_pengguna = s.id_pengguna
    WHERE s.id_siswa = ?
      AND s.id_pengguna = ?
      AND p.peran = 'siswa'
    LIMIT 1
");
$stmt->execute([$idSiswa, $idPengguna]);
$dataSiswa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dataSiswa) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

$namaSiswa = $dataSiswa['nama_lengkap'] ?: $namaSiswa;

$filter = $_GET['status'] ?? '';

$statusValid = [
    '',
    'menunggu',
    'diproses',
    'diterima',
    'ditolak',
    'dibatalkan'
];

if (!is_string($filter) || !in_array($filter, $statusValid, true)) {
    $filter = '';
}

$pesan = '';
if (
    isset($_GET['berhasil']) &&
    is_string($_GET['berhasil']) &&
    $_GET['berhasil'] === 'batal'
) {
    $pesan = 'Pendaftaran berhasil dibatalkan.';
}

$labelsStatus = [
    'menunggu' => 'Menunggu',
    'diproses' => 'Diproses',
    'diterima' => 'Diterima',
    'ditolak' => 'Ditolak',
    'dibatalkan' => 'Dibatalkan',
];

// Ambil riwayat hanya milik siswa yang sedang login.
$sql = "
    SELECT
        pg.id_pendaftaran,
        pg.tanggal_daftar,
        pg.status,
        pg.catatan,
        l.id_lowongan,
        l.judul_lowongan,
        l.tanggal_mulai,
        l.tanggal_selesai,
        p.nama_perusahaan,
        p.kota,
        j.kode_jurusan,
        j.nama_jurusan
    FROM pendaftaran pg
    JOIN siswa s ON s.id_siswa = pg.id_siswa
    JOIN lowongan_pkl l ON l.id_lowongan = pg.id_lowongan
    JOIN perusahaan p ON p.id_perusahaan = l.id_perusahaan
    LEFT JOIN jurusan j ON j.id_jurusan = l.id_jurusan
    WHERE s.id_siswa = ?
      AND s.id_pengguna = ?
";

$params = [$idSiswa, $idPengguna];

if ($filter !== '') {
    $sql .= " AND pg.status = ?";
    $params[] = $filter;
}

$sql .= " ORDER BY pg.tanggal_daftar DESC, pg.id_pendaftaran DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$riwayat = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung total riwayat siswa.
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM pendaftaran pg
    WHERE pg.id_siswa = ?
");
$stmt->execute([$idSiswa]);
$total = (int) $stmt->fetchColumn();

// Ringkasan berdasarkan status pendaftaran.
$stmt = $pdo->prepare("
    SELECT pg.status, COUNT(*) AS jumlah
    FROM pendaftaran pg
    WHERE pg.id_siswa = ?
    GROUP BY pg.status
");
$stmt->execute([$idSiswa]);

$ringkasan = [
    'menunggu' => 0,
    'diproses' => 0,
    'diterima' => 0,
    'ditolak' => 0,
    'dibatalkan' => 0,
];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($ringkasan[$row['status']])) {
        $ringkasan[$row['status']] = (int) $row['jumlah'];
    }
}

function labelStatus(string $status, array $labels): string
{
    return $labels[$status] ?? ucfirst($status);
}

// Format tanggal ke bahasa Indonesia.
function formatTanggalIndonesia($tanggal): string
{
    if (empty($tanggal)) {
        return '-';
    }

    $waktu = strtotime((string) $tanggal);

    if ($waktu === false) {
        return '-';
    }

    $bulan = [
        1 => 'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ];

    return date('j', $waktu) . ' ' .
        $bulan[(int) date('n', $waktu)] . ' ' .
        date('Y', $waktu);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Riwayat Pendaftaran | CariTempatPKL</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f7fd;color:#172744;font-family:Arial,sans-serif}
.page{max-width:1180px;margin:30px auto;padding:0 22px}
.hero{padding:28px 30px;background:linear-gradient(120deg,#1944b5,#3470f2);color:#fff;border-radius:18px;margin-bottom:22px}
.hero h1{font-size:28px;margin:8px 0}
.hero p{margin:0;color:#e6edff;line-height:1.6}
.welcome{font-size:12px;color:#e6edff}
.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:22px}
.stat,.panel,.item{background:#fff;border:1px solid #e4eaf4;border-radius:14px}
.stat{padding:17px}
.stat span{font-size:12px;color:#71809c}
.stat strong{display:block;font-size:25px;margin-top:8px;color:#173f80}
.panel{padding:20px;margin-bottom:20px}
.panel h2{font-size:18px;margin:0 0 15px}
.filter{display:flex;align-items:center;flex-wrap:wrap;gap:10px}
select{font:inherit;padding:10px 12px;border:1px solid #d9e1ef;border-radius:9px;background:#fff}
.btn{display:inline-block;border:0;border-radius:9px;background:#2c5ce5;color:#fff;padding:11px 15px;text-decoration:none;font-size:13px;font-weight:700;cursor:pointer}
.btn:hover{background:#1948c7}
.btn-secondary{background:#eaf1ff;color:#214c9c}
.list{display:grid;gap:14px}
.item{padding:20px}
.item-top{display:flex;justify-content:space-between;align-items:flex-start;gap:15px}
.item h3{margin:0 0 7px;font-size:18px;color:#183d78}
.company{font-size:14px;color:#475569}
.meta{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:15px;color:#64748b;font-size:12px}
.status{display:inline-block;white-space:nowrap;padding:7px 10px;border-radius:20px;font-size:11px;font-weight:800}
.menunggu{background:#fff3d7;color:#986300}
.diproses{background:#e7efff;color:#2457c5}
.diterima{background:#e2f8ea;color:#18723a}
.ditolak,.dibatalkan{background:#ffe7e7;color:#b42323}
.note{background:#f8faff;border-left:3px solid #9bb8f8;padding:12px 14px;margin-top:15px;border-radius:0 8px 8px 0;font-size:13px;line-height:1.6;color:#475569;overflow-wrap:anywhere}
.note strong{display:block;margin-bottom:4px;color:#1e3a66}
.empty{text-align:center;padding:38px 20px;color:#64748b;line-height:1.7}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;background:#e2f8ea;color:#18723a}
.footer{text-align:center;color:#94a3b8;font-size:12px;padding:20px 0}
@media(max-width:900px){.stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:600px){.page{padding:0 12px;margin:18px auto}.hero{padding:22px}.hero h1{font-size:24px}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.item{padding:16px}.item-top{flex-direction:column}.panel{padding:16px}}
</style>
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a href="cari_perusahaan.php" class="brand">
            <span class="brand-mark">PKL</span>
            <span>CariTempat<span style="color:#2c5ce5">PKL</span></span>
        </a>
        <div class="user-menu">
            <strong><?= h($namaSiswa) ?></strong>
            <a href="logout.php" class="logout-link">Keluar</a>
        </div>
    </div>
</header>

<main class="page">
    <section class="hero">
        <div class="welcome">AREA SISWA</div>
        <h1>Riwayat Pendaftaran PKL</h1>
        <p>Pantau status lamaran PKL dan baca catatan dari pengelola di sini.</p>
    </section>

    <?php if ($pesan !== ''): ?>
        <div class="alert"><?= h($pesan) ?></div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat"><span>Total pendaftaran</span><strong><?= $total ?></strong></div>
        <div class="stat"><span>Menunggu</span><strong><?= $ringkasan['menunggu'] ?></strong></div>
        <div class="stat"><span>Diproses</span><strong><?= $ringkasan['diproses'] ?></strong></div>
        <div class="stat"><span>Diterima</span><strong><?= $ringkasan['diterima'] ?></strong></div>
        <div class="stat"><span>Ditolak</span><strong><?= $ringkasan['ditolak'] ?></strong></div>
        <div class="stat">
    <span>Dibatalkan</span>
    <strong><?= $ringkasan['dibatalkan'] ?></strong>
</div>
    </section>

    <section class="panel">
        <h2>Filter riwayat</h2>
        <form method="GET" class="filter">
            <label for="status">Status pendaftaran:</label>
            <select name="status" id="status">
                <option value="">Semua status</option>
                <?php foreach ($statusValid as $s): ?>
                    <?php if ($s !== ''): ?>
                        <option value="<?= h($s) ?>" <?= $filter === $s ? 'selected' : '' ?>>
                            <?= h(labelStatus($s, $labelsStatus)) ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Terapkan</button>
            <a class="btn btn-secondary" href="riwayat_pendaftaran.php">Reset</a>
            <a class="btn btn-secondary" href="cari_perusahaan.php">Cari lowongan</a>
        </form>
    </section>

    <section class="list">
        <?php if (!$riwayat): ?>
            <div class="item empty">
                <strong>Belum ada riwayat pendaftaran.</strong><br>
                <?php if ($filter !== ''): ?>
                    Tidak ada pendaftaran dengan status <?= h(labelStatus($filter, $labelsStatus)) ?>.
                <?php else: ?>
                    Silakan cari lowongan yang sesuai dan lakukan pendaftaran terlebih dahulu.
                <?php endif; ?>
                <p style="margin-top:15px"><a class="btn" href="cari_perusahaan.php">Cari Lowongan PKL</a></p>
            </div>
        <?php else: ?>
            <?php foreach ($riwayat as $row): ?>
                <article class="item">
                    <div class="item-top">
                        <div>
                            <h3><?= h($row['judul_lowongan']) ?></h3>
                            <div class="company"><?= h($row['nama_perusahaan']) ?><?= !empty($row['kota']) ? ' · ' . h($row['kota']) : '' ?></div>
                        </div>
                        <span class="status <?= h($row['status']) ?>">
                            <?= h(labelStatus($row['status'], $labelsStatus)) ?>
                        </span>
                    </div>
                    <div class="meta">
                        <span>
    📅 Tanggal daftar:
    <?= h(formatTanggalIndonesia($row['tanggal_daftar'])) ?>
</span>
                        <span>🎓 Jurusan: <?= h(trim(($row['kode_jurusan'] ?? '') . ' ' . ($row['nama_jurusan'] ?? '')) ?: '-') ?></span>
                        <?php if (!empty($row['tanggal_mulai']) || !empty($row['tanggal_selesai'])): ?>
                            <span>
    🗓 Periode PKL:
    <?= h(formatTanggalIndonesia($row['tanggal_mulai'])) ?>
    s.d.
    <?= h(formatTanggalIndonesia($row['tanggal_selesai'])) ?>
</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($row['catatan'])): ?>
                        <div class="note">
                            <strong>Catatan pengelola</strong>
                            <?= nl2br(h($row['catatan'])) ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <div class="footer">© <?= date('Y') ?> CariTempatPKL — Riwayat Pendaftaran Siswa</div>
</main>
</body>
</html>
