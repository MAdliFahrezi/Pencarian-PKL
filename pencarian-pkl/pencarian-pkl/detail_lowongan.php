
<?php
session_start();
require_once 'koneksi.php';

// Pastikan hanya siswa yang login dapat mengakses halaman.
if (
    !isset($_SESSION['id_pengguna'], $_SESSION['id_siswa'], $_SESSION['peran']) ||
    $_SESSION['peran'] !== 'siswa'
) {
    header('Location: login.php');
    exit;
}

$idSiswa = filter_var($_SESSION['id_siswa'], FILTER_VALIDATE_INT);
$idPengguna = filter_var($_SESSION['id_pengguna'], FILTER_VALIDATE_INT);
$idLowongan = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$idSiswa || $idSiswa < 1 || !$idPengguna || $idPengguna < 1) {
    http_response_code(403);
    exit('Sesi siswa tidak valid. Silakan login kembali.');
}

if (!$idLowongan || $idLowongan < 1) {
    header('Location: cari_perusahaan.php');
    exit;
}

// Buat token CSRF untuk melindungi formulir pendaftaran.
if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$pesan = '';
$jenisPesan = 'info';

// Ambil data siswa dan pastikan terhubung ke akun yang login.
$stmt = $pdo->prepare("
    SELECT
        s.id_siswa,
        s.id_pengguna,
        s.id_jurusan,
        j.kode_jurusan,
        j.nama_jurusan,
        p.nama_lengkap
    FROM siswa s
    JOIN jurusan j ON j.id_jurusan = s.id_jurusan
    JOIN pengguna p ON p.id_pengguna = s.id_pengguna
    WHERE s.id_siswa = ?
      AND s.id_pengguna = ?
      AND p.peran = 'siswa'
    LIMIT 1
");
$stmt->execute([$idSiswa, $idPengguna]);
$siswa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$siswa) {
    http_response_code(403);
    exit('Data siswa tidak sesuai dengan akun yang login.');
}

// Proses pendaftaran hanya melalui POST dengan token CSRF yang valid.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenDikirim = $_POST['csrf_token'] ?? '';
    $idDikirim = $_POST['id_lowongan'] ?? '';

    if (
        !is_string($tokenDikirim) ||
        !hash_equals($_SESSION['csrf_token'], $tokenDikirim) ||
        !is_string($idDikirim) ||
        !ctype_digit($idDikirim) ||
        (int) $idDikirim !== $idLowongan
    ) {
        http_response_code(400);
        $pesan = 'Permintaan tidak valid atau sesi formulir telah berakhir. Muat ulang halaman.';
        $jenisPesan = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            // Kunci baris lowongan agar pemeriksaan kuota terkoordinasi.
            $stmt = $pdo->prepare("
                SELECT
                    l.id_lowongan,
                    l.id_jurusan,
                    l.kuota,
                    l.status,
                    (
                        SELECT COUNT(*)
                        FROM pendaftaran pd
                        WHERE pd.id_lowongan = l.id_lowongan
                          AND pd.status = 'diterima'
                    ) AS jumlah_diterima
                FROM lowongan_pkl l
                WHERE l.id_lowongan = ?
                FOR UPDATE
            ");
            $stmt->execute([$idLowongan]);
            $cekLowongan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$cekLowongan ||
                $cekLowongan['status'] !== 'dibuka'
            ) {
                $pdo->rollBack();
                $pesan = 'Lowongan ini sudah tidak dibuka.';
                $jenisPesan = 'error';
            } elseif (
                (int) $cekLowongan['id_jurusan'] !==
                (int) $siswa['id_jurusan']
            ) {
                $pdo->rollBack();
                $pesan = 'Kamu hanya bisa mendaftar lowongan sesuai jurusanmu.';
                $jenisPesan = 'error';
            } elseif (
                (int) $cekLowongan['jumlah_diterima'] >=
                (int) $cekLowongan['kuota']
            ) {
                $pdo->rollBack();
                $pesan = 'Maaf, kuota lowongan ini sudah penuh.';
                $jenisPesan = 'error';
            } else {
                // UNIQUE (id_siswa, id_lowongan) tetap menjadi
                // perlindungan terakhir terhadap pendaftaran ganda.
                $stmt = $pdo->prepare("
                    INSERT INTO pendaftaran
                        (id_siswa, id_lowongan, tanggal_daftar, status)
                    VALUES (?, ?, NOW(), 'menunggu')
                ");

                $stmt->execute([$idSiswa, $idLowongan]);

// Ambil ID pendaftaran yang baru dibuat.
$idPendaftaran = (int) $pdo->lastInsertId();

// Cari akun perusahaan pemilik lowongan.
$stmt = $pdo->prepare("
    SELECT
        pr.id_pengguna,
        pr.nama_perusahaan,
        l.judul_lowongan
    FROM lowongan_pkl l
    JOIN perusahaan pr
        ON pr.id_perusahaan = l.id_perusahaan
    WHERE l.id_lowongan = ?
    LIMIT 1
");
$stmt->execute([$idLowongan]);
$dataPerusahaan = $stmt->fetch(PDO::FETCH_ASSOC);

// Buat notifikasi untuk perusahaan jika memiliki akun.
if (
    $dataPerusahaan &&
    !empty($dataPerusahaan['id_pengguna'])
) {
    $judulNotifikasi = 'Pendaftaran PKL Baru';

    $pesanNotifikasi =
        $siswa['nama_lengkap'] .
        ' mendaftar lowongan "' .
        $dataPerusahaan['judul_lowongan'] .
        '". Silakan periksa daftar pendaftaran.';

    $stmt = $pdo->prepare("
        INSERT INTO notifikasi
            (id_pengguna, id_pendaftaran, judul, pesan, dibaca, dibuat_pada)
        VALUES
            (?, ?, ?, ?, 0, NOW())
    ");

    $stmt->execute([
        (int) $dataPerusahaan['id_pengguna'],
        $idPendaftaran,
        $judulNotifikasi,
        $pesanNotifikasi
    ]);
}

// Simpan pendaftaran dan notifikasi bersama-sama.
$pdo->commit();

$pesan = 'Pendaftaran berhasil dikirim! Status awal: menunggu.';
$jenisPesan = 'success';

// Rotasi token setelah pendaftaran berhasil.
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                // Rotasi token setelah berhasil memproses formulir.
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Pendaftaran PKL gagal: ' . $ex->getMessage());

            // SQLSTATE 23000 dapat menunjukkan pelanggaran UNIQUE.
            if ($ex->getCode() === '23000') {
                $pesan = 'Kamu sudah pernah mendaftar lowongan ini.';
                $jenisPesan = 'info';
            } else {
                $pesan = 'Pendaftaran belum berhasil diproses. Silakan coba lagi.';
                $jenisPesan = 'error';
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Kesalahan pendaftaran PKL: ' . $ex->getMessage());
            $pesan = 'Terjadi kesalahan. Silakan coba lagi.';
            $jenisPesan = 'error';
        }
    }
}

// Ambil detail lowongan, perusahaan, dan jumlah siswa diterima.
$stmt = $pdo->prepare("
    SELECT
        l.id_lowongan,
        l.judul_lowongan,
        l.kuota,
        l.persyaratan,
        l.tanggal_mulai,
        l.tanggal_selesai,
        l.status,
        j.id_jurusan,
        j.kode_jurusan,
        j.nama_jurusan,
        pr.nama_perusahaan,
        pr.bidang_usaha,
        pr.alamat,
        pr.kota,
        pr.telepon,
        pr.email,
        pr.deskripsi,
        (
            SELECT COUNT(*)
            FROM pendaftaran pd
            WHERE pd.id_lowongan = l.id_lowongan
              AND pd.status = 'diterima'
        ) AS jumlah_diterima
    FROM lowongan_pkl l
    JOIN perusahaan pr ON pr.id_perusahaan = l.id_perusahaan
    JOIN jurusan j ON j.id_jurusan = l.id_jurusan
    WHERE l.id_lowongan = ?
    LIMIT 1
");
$stmt->execute([$idLowongan]);
$lowongan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lowongan) {
    http_response_code(404);
    exit('Lowongan tidak ditemukan.');
}

// Cek apakah siswa sudah pernah mendaftar.
$stmt = $pdo->prepare("
    SELECT status
    FROM pendaftaran
    WHERE id_siswa = ? AND id_lowongan = ?
    LIMIT 1
");
$stmt->execute([$idSiswa, $idLowongan]);
$statusPendaftaran = $stmt->fetchColumn();

$sisaKuota = max(
    0,
    (int) $lowongan['kuota'] - (int) $lowongan['jumlah_diterima']
);

$bisaDaftar = (
    $lowongan['status'] === 'dibuka'
    && (int) $lowongan['id_jurusan'] === (int) $siswa['id_jurusan']
    && $sisaKuota > 0
    && $statusPendaftaran === false
);

function tanggalIndonesia($tanggal): string
{
    if (!$tanggal) {
        return 'Belum ditentukan';
    }

    $waktu = strtotime($tanggal);

    if ($waktu === false) {
        return 'Belum ditentukan';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Lowongan - CariTempatPKL</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .detail-wrap { max-width: 1120px; margin: 36px auto 64px; padding: 0 24px; }
        .back-link { display: inline-block; color: #2457d6; text-decoration: none; font-weight: 700; margin-bottom: 22px; }
        .detail-grid { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(280px, .8fr); gap: 24px; align-items: start; }
        .detail-card { background: #fff; border: 1px solid #e3e9f4; border-radius: 20px; padding: 28px; box-shadow: 0 8px 28px rgba(31, 55, 105, .04); margin-bottom: 20px; }
        .company-icon { width: 64px; height: 64px; display: grid; place-items: center; border-radius: 17px; background: #e9efff; color: #2457d6; font-size: 27px; font-weight: 800; margin-bottom: 18px; }
        .detail-eyebrow { color: #2457d6; text-transform: uppercase; letter-spacing: 1.4px; font-size: 11px; font-weight: 800; }
        .detail-title { color: #142344; font-size: clamp(26px, 4vw, 36px); line-height: 1.2; margin: 10px 0; }
        .detail-muted { color: #71809c; line-height: 1.7; }
        .detail-pill { display: inline-block; background: #edf3ff; color: #2457d6; border-radius: 999px; padding: 8px 12px; font-size: 12px; font-weight: 700; margin: 8px 6px 0 0; }
        .detail-section-title { font-size: 18px; color: #172744; margin: 0 0 14px; }
        .detail-body { color: #4f5f7a; line-height: 1.8; white-space: pre-line; overflow-wrap: anywhere; }
        .detail-info-row { display: flex; justify-content: space-between; gap: 16px; padding: 13px 0; border-bottom: 1px solid #edf0f6; }
        .detail-info-row:last-child { border-bottom: 0; }
        .detail-info-row span { color: #71809c; }
        .detail-info-row strong { color: #172744; text-align: right; overflow-wrap: anywhere; }
        .quota-number { color: #2457d6; font-size: 34px; font-weight: 800; }
        .quota-track { height: 8px; border-radius: 20px; background: #e9eef8; overflow: hidden; margin: 12px 0; }
        .quota-fill { height: 100%; background: #3266e9; border-radius: inherit; }
        .apply-button { display: block; width: 100%; border: 0; border-radius: 12px; padding: 15px 18px; margin-top: 18px; color: #fff; background: #2c5ce5; font-weight: 800; font-size: 15px; cursor: pointer; }
        .apply-button:hover { background: #1948c7; }
        .apply-button:disabled { background: #aab6cd; cursor: not-allowed; }
        .detail-alert { padding: 14px 16px; border-radius: 12px; margin-bottom: 22px; line-height: 1.6; }
        .detail-alert.success { background: #eafaf1; color: #17653a; }
        .detail-alert.error { background: #fff0f0; color: #a52828; }
        .detail-alert.info { background: #edf3ff; color: #214caa; }
        .detail-note { font-size: 12px; line-height: 1.6; color: #7a879e; margin-top: 12px; }
        @media (max-width: 760px) {
            .detail-grid { grid-template-columns: 1fr; }
            .detail-wrap { padding: 0 16px; margin-top: 24px; }
            .detail-card { padding: 21px; }
        }
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
            <div class="user-avatar"><?= e(strtoupper(substr($siswa['nama_lengkap'], 0, 1))) ?></div>
            <div>
                <strong><?= e($siswa['nama_lengkap']) ?></strong><br>
                <small><?= e($siswa['kode_jurusan']) ?></small>
            </div>
            <a href="status_pendaftaran.php">Status Pendaftaran</a>
            <a href="logout.php" class="logout-link">Keluar</a>
        </div>
    </div>
</header>

<main class="detail-wrap">
    <a class="back-link" href="cari_perusahaan.php">← Kembali ke daftar lowongan</a>

    <?php if ($pesan !== ''): ?>
        <div class="detail-alert <?= e($jenisPesan) ?>" role="status">
            <?= e($pesan) ?>
        </div>
    <?php endif; ?>

    <div class="detail-grid">
        <section>
            <div class="detail-card">
                <div class="company-icon"><?= e(strtoupper(substr($lowongan['nama_perusahaan'], 0, 1))) ?></div>
                <div class="detail-eyebrow">Detail kesempatan PKL</div>
                <h1 class="detail-title"><?= e($lowongan['judul_lowongan']) ?></h1>
                <p class="detail-muted">
                    <?= e($lowongan['nama_perusahaan']) ?>
                    <?php if (!empty($lowongan['kota'])): ?>
                        · <?= e($lowongan['kota']) ?>
                    <?php endif; ?>
                </p>
                <span class="detail-pill"><?= e($lowongan['nama_jurusan']) ?></span>
                <span class="detail-pill">
                    <?= $lowongan['status'] === 'dibuka' ? '● Lowongan dibuka' : 'Lowongan ditutup' ?>
                </span>
            </div>

            <div class="detail-card">
                <h2 class="detail-section-title">Tentang perusahaan</h2>
                <p class="detail-body"><?= e($lowongan['deskripsi'] ?: 'Informasi tentang perusahaan belum ditambahkan.') ?></p>
                <?php if (!empty($lowongan['bidang_usaha'])): ?>
                    <div class="detail-info-row">
                        <span>Bidang usaha</span>
                        <strong><?= e($lowongan['bidang_usaha']) ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (!empty($lowongan['alamat']) || !empty($lowongan['kota'])): ?>
                    <div class="detail-info-row">
                        <span>Alamat</span>
                        <strong><?= e(trim(($lowongan['alamat'] ?? '') . ' ' . ($lowongan['kota'] ?? ''))) ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (!empty($lowongan['telepon'])): ?>
                    <div class="detail-info-row">
                        <span>Telepon</span>
                        <strong><?= e($lowongan['telepon']) ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (!empty($lowongan['email'])): ?>
                    <div class="detail-info-row">
                        <span>Email</span>
                        <strong><?= e($lowongan['email']) ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <div class="detail-card">
                <h2 class="detail-section-title">Persyaratan PKL</h2>
                <p class="detail-body"><?= e($lowongan['persyaratan'] ?: 'Belum ada persyaratan khusus yang dicantumkan.') ?></p>
            </div>
        </section>

        <aside>
            <div class="detail-card">
                <div class="detail-eyebrow">Informasi kuota</div>
                <div class="quota-number"><?= $sisaKuota ?> <span style="font-size:15px;font-weight:600;color:#71809c">kursi tersisa</span></div>
                <div class="quota-track">
                    <div class="quota-fill" style="width:<?= min(100, (int) round(((int) $lowongan['jumlah_diterima'] / max(1, (int) $lowongan['kuota'])) * 100)) ?>%"></div>
                </div>
                <p class="detail-muted">
                    <?= (int) $lowongan['jumlah_diterima'] ?> dari <?= (int) $lowongan['kuota'] ?> kuota telah terisi.
                </p>

                <div class="detail-info-row">
                    <span>Jurusan</span>
                    <strong><?= e($lowongan['kode_jurusan']) ?></strong>
                </div>
                <div class="detail-info-row">
                    <span>Mulai PKL</span>
                    <strong><?= e(tanggalIndonesia($lowongan['tanggal_mulai'])) ?></strong>
                </div>
                <div class="detail-info-row">
                    <span>Selesai PKL</span>
                    <strong><?= e(tanggalIndonesia($lowongan['tanggal_selesai'])) ?></strong>
                </div>

                <?php if ($statusPendaftaran !== false): ?>
                    <button class="apply-button" type="button" disabled>
                        Sudah mendaftar · <?= e(ucfirst($statusPendaftaran)) ?>
                    </button>
                <?php elseif ((int) $lowongan['id_jurusan'] !== (int) $siswa['id_jurusan']): ?>
                    <button class="apply-button" type="button" disabled>
                        Khusus jurusan <?= e($lowongan['kode_jurusan']) ?>
                    </button>
                <?php elseif ($lowongan['status'] !== 'dibuka' || $sisaKuota < 1): ?>
                    <button class="apply-button" type="button" disabled>
                        <?= $sisaKuota < 1 ? 'Kuota sudah penuh' : 'Lowongan ditutup' ?>
                    </button>
                <?php else: ?>
                    <form method="POST" action="detail_lowongan.php?id=<?= (int) $lowongan['id_lowongan'] ?>"
                          onsubmit="return confirm('Yakin ingin mendaftar lowongan ini?');">
                        <input type="hidden" name="id_lowongan" value="<?= (int) $lowongan['id_lowongan'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <button class="apply-button" type="submit">Daftar PKL sekarang →</button>
                    </form>
                <?php endif; ?>

                <p class="detail-note">
                    Pendaftaran akan tercatat dengan status awal <strong>menunggu</strong>.
                    Pastikan kamu membaca informasi dan persyaratan terlebih dahulu.
                </p>
            </div>
        </aside>
    </div>
</main>
</body>
</html>