<?php
session_start();
require_once 'koneksi.php';

// Pastikan akun yang login adalah siswa.
if (
    !isset($_SESSION['id_pengguna']) ||
    !isset($_SESSION['id_siswa']) ||
    !isset($_SESSION['peran']) ||
    $_SESSION['peran'] !== 'siswa'
) {
    header('Location: login.php');
    exit;
}

$idSiswa = filter_var($_SESSION['id_siswa'], FILTER_VALIDATE_INT);
$idPengguna = filter_var($_SESSION['id_pengguna'], FILTER_VALIDATE_INT);

if (!$idSiswa || $idSiswa < 1 || !$idPengguna || $idPengguna < 1) {
    http_response_code(403);
    exit('Sesi pengguna tidak valid. Silakan login kembali.');
}

// Validasi parameter pencarian.
$cariInput = $_GET['q'] ?? '';
$cari = is_string($cariInput) ? trim($cariInput) : '';

$jurusanInput = $_GET['jurusan'] ?? '';
$filterJurusan = is_string($jurusanInput)
    ? trim($jurusanInput)
    : '';

// Ambil data siswa dan pastikan terhubung dengan akun yang login.
$stmt = $pdo->prepare(
    "SELECT
        s.id_siswa,
        s.id_pengguna,
        s.nis,
        s.kelas,
        j.id_jurusan,
        j.kode_jurusan,
        j.nama_jurusan,
        p.nama_lengkap
     FROM siswa s
     JOIN jurusan j ON j.id_jurusan = s.id_jurusan
     JOIN pengguna p ON p.id_pengguna = s.id_pengguna
     WHERE s.id_siswa = ?
       AND s.id_pengguna = ?
       AND p.peran = 'siswa'
     LIMIT 1"
);

$stmt->execute([$idSiswa, $idPengguna]);
$siswa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$siswa) {
    http_response_code(403);
    exit('Data siswa tidak sesuai dengan akun yang login.');
}

// Fungsi untuk mengamankan output HTML.
function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Pencarian dan filter jurusan.
$sql = "SELECT
            l.id_lowongan,
            l.judul_lowongan,
            l.kuota,
            l.persyaratan,
            l.tanggal_mulai,
            l.tanggal_selesai,
            j.kode_jurusan,
            j.nama_jurusan,
            p.nama_perusahaan,
            p.bidang_usaha,
            p.kota,
            p.alamat,
            (
                SELECT COUNT(*)
                FROM pendaftaran d
                WHERE d.id_lowongan = l.id_lowongan
                  AND d.status = 'diterima'
            ) AS jumlah_diterima
        FROM lowongan_pkl l
        JOIN perusahaan p
          ON p.id_perusahaan = l.id_perusahaan
        JOIN jurusan j
          ON j.id_jurusan = l.id_jurusan
        WHERE l.status = 'dibuka'";

$params = [];

if ($filterJurusan !== '') {
    $sql .= " AND j.kode_jurusan = ?";
    $params[] = $filterJurusan;
} else {
    $sql .= " AND j.id_jurusan = ?";
    $params[] = $siswa['id_jurusan'];
}

if ($cari !== '') {
    $sql .= " AND (
        p.nama_perusahaan LIKE ?
        OR p.bidang_usaha LIKE ?
        OR p.kota LIKE ?
        OR l.judul_lowongan LIKE ?
    )";

    $keyword = '%' . $cari . '%';

    array_push(
        $params,
        $keyword,
        $keyword,
        $keyword,
        $keyword
    );
}

$sql .= " ORDER BY p.nama_perusahaan ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lowongan = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ambil daftar jurusan untuk filter.
$daftarJurusan = $pdo->query(
    "SELECT kode_jurusan, nama_jurusan
     FROM jurusan
     ORDER BY kode_jurusan"
)->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cari Tempat PKL</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="topbar">
    <a class="brand" href="cari_perusahaan.php">
        <span class="brand-icon">PKL</span>
        <span>CariTempat<span class="brand-blue">PKL</span></span>
    </a>

    <div class="user-menu">
        <div class="avatar">
            <?= e(strtoupper(substr($siswa['nama_lengkap'], 0, 1))) ?>
        </div>
        <div class="user-info">
            <strong><?= e($siswa['nama_lengkap']) ?></strong>
<span>
    Kelas <?= e($siswa['kelas']) ?> ·
    <?= e($siswa['kode_jurusan']) ?>
</span>
        </div>
        <a href="status_pendaftaran.php">Status Pendaftaran</a>
<a href="notifikasi.php">Notifikasi</a>
<a href="riwayat_pendaftaran.php">Riwayat Pendaftaran</a>
<a href="jurnal_siswa.php">Jurnal PKL</a>
<a class="logout-link" href="logout.php">Keluar</a>
    </div>
</header>

<main class="container">

    <section class="hero">
        <div class="hero-content">
            <span class="eyebrow">PORTAL PKL SISWA</span>
            <h1>Temukan tempat PKL<br>yang tepat untukmu.</h1>
            <p>
                Jelajahi perusahaan, temukan peluang sesuai jurusan,
                dan mulai perjalanan kariermu dari sekarang.
            </p>

            <div class="hero-tags">
                <span>✓ Sesuai jurusan</span>
                <span>✓ Informasi kuota</span>
                <span>✓ Pendaftaran online</span>
            </div>
        </div>
        <div class="hero-graphic" aria-hidden="true">
            <div class="graphic-circle">
                <span class="graphic-icon">⌕</span>
                <div class="graphic-card card-one">💼 Peluang PKL</div>
                <div class="graphic-card card-two">✓ Siap berkembang</div>
            </div>
        </div>
    </section>

    <section class="welcome-row">
        <div>
            <p class="muted">DASHBOARD SISWA</p>
            <h2>Halo, <?= e($siswa['nama_lengkap']) ?>! 👋</h2>
            <p class="subheading">
                Berikut lowongan PKL yang bisa kamu jelajahi.
            </p>
        </div>
        <div class="major-badge">
            <span class="major-dot"></span>
            Jurusan <?= e($siswa['kode_jurusan']) ?>
        </div>
    </section>

    <section class="search-panel">
        <form method="get" action="cari_perusahaan.php" class="search-form">
            <label class="search-input">
                <span class="search-icon">⌕</span>
                <input
                    type="search"
                    name="q"
                    value="<?= e($cari) ?>"
                    placeholder="Cari nama perusahaan, bidang, atau kota..."
                >
            </label>

            <select name="jurusan" aria-label="Filter jurusan">
                <option value="">Jurusan saya</option>
                <?php foreach ($daftarJurusan as $j): ?>
                    <option
                        value="<?= e($j['kode_jurusan']) ?>"
                        <?= $filterJurusan === $j['kode_jurusan'] ? 'selected' : '' ?>
                    >
                        <?= e($j['kode_jurusan']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-primary">Cari lowongan</button>
        </form>
        <p class="search-note">
            Secara default, lowongan ditampilkan sesuai jurusanmu.
            Kamu dapat memilih jurusan lain untuk melihat lowongannya.
        </p>
    </section>

    <section class="listing-section">
        <div class="section-heading">
            <div>
                <h2>Lowongan PKL</h2>
                <p class="subheading">
                    <?= count($lowongan) ?> lowongan ditemukan
                </p>
            </div>
            <span class="result-label">Terbaru</span>
        </div>

        <?php if (!$lowongan): ?>
            <div class="empty-state">
                <div class="empty-icon">⌕</div>
                <h3>Lowongan belum ditemukan</h3>
                <p>
                    Coba kata kunci lain atau pilih jurusan yang berbeda.
                </p>
                <a class="btn-secondary" href="cari_perusahaan.php">
                    Reset pencarian
                </a>
            </div>
        <?php else: ?>
            <div class="company-grid">
                <?php foreach ($lowongan as $item): ?>
                    <?php
                    $tersisa = max(
                        0,
                        (int)$item['kuota'] - (int)$item['jumlah_diterima']
                    );
                    ?>
                    <article class="company-card">
                        <div class="company-card-top">
                            <div class="company-logo">
                                <?= e(strtoupper(substr(
                                    $item['nama_perusahaan'], 0, 1
                                ))) ?>
                            </div>
                            <span class="status-pill">
                                ● <?= $tersisa > 0 ? 'Dibuka' : 'Kuota penuh' ?>
                            </span>
                        </div>

                        <div class="company-info">
                            <p class="company-category">
                                <?= e($item['bidang_usaha'] ?: 'Bidang usaha belum diisi') ?>
                            </p>
                            <h3><?= e($item['nama_perusahaan']) ?></h3>
                            <p class="company-location">
                                <span>⌖</span>
                                <?= e($item['kota'] ?: 'Lokasi belum diisi') ?>
                            </p>
                        </div>

                        <div class="major-tags">
                            <span><?= e($item['kode_jurusan']) ?></span>
                        </div>

                        <p class="job-title">
                            <?= e($item['judul_lowongan']) ?>
                        </p>

                        <div class="quota-box">
                            <div>
                                <span class="quota-label">Kuota diterima</span>
                                <strong>
                                    <?= (int)$item['jumlah_diterima'] ?>
                                    / <?= (int)$item['kuota'] ?> siswa
                                </strong>
                            </div>
                            <span class="quota-remaining">
                                <?= $tersisa ?> tersisa
                            </span>
                        </div>

                        <div class="card-footer">
                            <a
                                class="btn-card <?= $tersisa === 0 ? 'btn-disabled' : '' ?>"
                                href="detail_lowongan.php?id=<?= (int)$item['id_lowongan'] ?>"
                                <?= $tersisa === 0 ? 'aria-disabled="true"' : '' ?>
                            >
                                <?= $tersisa > 0 ? 'Lihat detail →' : 'Lihat informasi' ?>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <footer class="footer">
        <p>© <?= date('Y') ?> CariTempatPKL · Project Kelompok 5</p>
        <p>Membantu siswa menemukan peluang PKL.</p>
    </footer>

</main>
</body>
</html>
