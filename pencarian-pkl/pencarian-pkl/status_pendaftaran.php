

<?php
session_start();
require_once 'koneksi.php';

// Pastikan hanya akun siswa yang dapat mengakses halaman.
if (
    !isset($_SESSION['id_pengguna'], $_SESSION['id_siswa'], $_SESSION['peran']) ||
    $_SESSION['peran'] !== 'siswa'
) {
    header('Location: login.php');
    exit;
}

$idSiswa = filter_var($_SESSION['id_siswa'], FILTER_VALIDATE_INT);
$idPengguna = filter_var($_SESSION['id_pengguna'], FILTER_VALIDATE_INT);

if (!$idSiswa || $idSiswa < 1 || !$idPengguna || $idPengguna < 1) {
    http_response_code(403);
    exit('Sesi tidak valid. Silakan login kembali.');
}

// Amankan output HTML.
function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Format tanggal dalam bahasa Indonesia.
function tanggalIndonesia($tanggal): string
{
    if (!$tanggal) {
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

// Informasi tampilan untuk setiap status.
$statusInfo = [
    'menunggu' => [
        'label' => 'Menunggu',
        'class' => 'waiting',
        'icon' => '◷',
        'deskripsi' => 'Pendaftaran kamu menunggu pemeriksaan.'
    ],
    'diproses' => [
        'label' => 'Diproses',
        'class' => 'processing',
        'icon' => '↻',
        'deskripsi' => 'Pendaftaran kamu sedang ditinjau.'
    ],
    'diterima' => [
        'label' => 'Diterima',
        'class' => 'accepted',
        'icon' => '✓',
        'deskripsi' => 'Selamat! Pendaftaran PKL kamu diterima.'
    ],
    'ditolak' => [
        'label' => 'Ditolak',
        'class' => 'rejected',
        'icon' => '×',
        'deskripsi' => 'Pendaftaran kamu belum diterima.'
    ],
    'dibatalkan' => [
        'label' => 'Dibatalkan',
        'class' => 'cancelled',
        'icon' => '−',
        'deskripsi' => 'Pendaftaran ini telah dibatalkan.'
    ]
];

// Ambil identitas siswa sesuai akun yang login.
$stmt = $pdo->prepare("
    SELECT
        p.nama_lengkap,
        j.kode_jurusan,
        j.nama_jurusan
    FROM siswa s
    JOIN pengguna p ON p.id_pengguna = s.id_pengguna
    JOIN jurusan j ON j.id_jurusan = s.id_jurusan
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

// Ambil hanya pendaftaran milik siswa yang login.
$stmt = $pdo->prepare("
    SELECT
        pd.id_pendaftaran,
        pd.id_lowongan,
        pd.tanggal_daftar,
        pd.status,
        pd.catatan,
        l.judul_lowongan,
        l.tanggal_mulai,
        l.tanggal_selesai,
        l.status AS status_lowongan,
        pr.nama_perusahaan,
        pr.bidang_usaha,
        pr.kota,
        j.kode_jurusan,
        j.nama_jurusan
    FROM pendaftaran pd
    JOIN lowongan_pkl l ON l.id_lowongan = pd.id_lowongan
    JOIN perusahaan pr ON pr.id_perusahaan = l.id_perusahaan
    JOIN jurusan j ON j.id_jurusan = l.id_jurusan
    WHERE pd.id_siswa = ?
    ORDER BY pd.tanggal_daftar DESC, pd.id_pendaftaran DESC
");

$stmt->execute([$idSiswa]);
$pendaftaran = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung jumlah pendaftaran berdasarkan status.
$total = count($pendaftaran);
$menunggu = 0;
$diproses = 0;
$diterima = 0;
$ditolak = 0;

foreach ($pendaftaran as $item) {
    switch ($item['status']) {
        case 'menunggu':
            $menunggu++;
            break;

        case 'diproses':
            $diproses++;
            break;

        case 'diterima':
            $diterima++;
            break;

        case 'ditolak':
            $ditolak++;
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Pendaftaran - CariTempatPKL</title>
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        body {
            background: #f4f7fd;
            color: #172744;
        }

        .status-page {
            max-width: 1120px;
            margin: 36px auto 60px;
            padding: 0 24px;
        }

        .status-hero {
            padding: 34px;
            color: white;
            border-radius: 22px;
            background: linear-gradient(120deg, #1944b5, #3470f2);
            margin-bottom: 28px;
        }

        .status-hero .eyebrow {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1.6px;
            opacity: .85;
        }

        .status-hero h1 {
            margin: 12px 0;
            font-size: clamp(27px, 4vw, 36px);
        }

        .status-hero p {
            line-height: 1.7;
            margin-bottom: 0;
            color: #e7edff;
        }

        .status-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }

        .summary-card {
            padding: 20px;
            background: white;
            border: 1px solid #e5eaf4;
            border-radius: 17px;
        }

        .summary-card span {
            color: #7785a0;
            font-size: 13px;
        }

        .summary-card strong {
            display: block;
            margin-top: 8px;
            font-size: 28px;
        }

        .section-heading {
            margin: 0 0 18px;
            font-size: 23px;
        }

        .application-card {
            background: white;
            border: 1px solid #e4eaf4;
            border-radius: 18px;
            padding: 24px;
            margin-bottom: 17px;
            box-shadow: 0 5px 20px rgba(31, 55, 105, .035);
        }

        .application-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }

        .company-initial {
            display: inline-grid;
            place-items: center;
            width: 52px;
            height: 52px;
            border-radius: 15px;
            background: #eaf0ff;
            color: #2457d6;
            font-size: 21px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .application-title {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .application-title h3 {
            margin: 2px 0 7px;
            font-size: 19px;
            line-height: 1.4;
        }

        .muted {
            color: #7785a0;
            font-size: 13px;
            line-height: 1.7;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .waiting {
            background: #fff3d7;
            color: #986300;
        }

        .processing {
            background: #e7efff;
            color: #2457c5;
        }

        .accepted {
            background: #e2f8ea;
            color: #18723a;
        }

        .rejected {
            background: #ffe7e7;
            color: #b42323;
        }

        .cancelled {
            background: #edf0f5;
            color: #58657b;
        }

        .application-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 24px;
            margin: 20px 0;
            padding: 17px 0;
            border-top: 1px solid #edf0f6;
            border-bottom: 1px solid #edf0f6;
        }

        .application-meta span {
            color: #71809c;
            font-size: 13px;
        }

        .status-description {
            margin: 0;
            color: #4d5c75;
            font-size: 14px;
            line-height: 1.7;
        }

        .company-note {
            margin-top: 15px;
            padding: 14px 16px;
            border-radius: 12px;
            background: #f3f6fc;
            color: #465674;
            font-size: 13px;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .application-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
        }

        .detail-link {
            display: inline-block;
            padding: 11px 16px;
            color: #2457d6;
            background: #edf3ff;
            border-radius: 10px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
        }

        .empty-state {
            background: white;
            border: 1px solid #e4eaf4;
            border-radius: 20px;
            text-align: center;
            padding: 50px 24px;
        }

        .empty-icon {
            font-size: 42px;
            margin-bottom: 12px;
        }

        .primary-link {
            display: inline-block;
            background: #2c5ce5;
            color: white;
            text-decoration: none;
            border-radius: 11px;
            padding: 13px 18px;
            font-weight: 800;
            font-size: 14px;
            margin-top: 14px;
        }

        @media (max-width: 760px) {
            .status-page {
                padding: 0 15px;
                margin-top: 22px;
            }

            .status-hero {
                padding: 25px;
            }

            .status-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .application-top {
                flex-direction: column;
            }

            .application-card {
                padding: 19px;
            }

            .application-title h3 {
                font-size: 17px;
            }
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
            <div class="user-avatar">
                <?= e(strtoupper(substr($siswa['nama_lengkap'], 0, 1))) ?>
            </div>
            <div>
                <strong><?= e($siswa['nama_lengkap']) ?></strong><br>
                <small><?= e($siswa['kode_jurusan']) ?></small>
            </div>
            <a href="logout.php" class="logout-link">Keluar</a>
        </div>
    </div>
</header>

<main class="status-page">

    <section class="status-hero">
        <div class="eyebrow">DASHBOARD SISWA</div>
        <h1>Status Pendaftaran PKL</h1>
        <p>Pantau perkembangan lamaran PKL kamu dalam satu halaman.</p>
    </section>

    <section class="status-summary">
        <div class="summary-card">
            <span>Total pendaftaran</span>
            <strong><?= $total ?></strong>
        </div>
        <div class="summary-card">
            <span>Menunggu</span>
            <strong><?= $menunggu ?></strong>
        </div>
        <div class="summary-card">
            <span>Diproses</span>
            <strong><?= $diproses ?></strong>
        </div>
        <div class="summary-card">
            <span>Diterima</span>
            <strong><?= $diterima ?></strong>
        </div>
        <div class="summary-card">
    <span>Ditolak</span>
    <strong><?= $ditolak ?></strong>
</div>
    </section>

    <h2 class="section-heading">Riwayat pendaftaran kamu</h2>

    <?php if (empty($pendaftaran)): ?>

        <div class="empty-state">
            <div class="empty-icon">📋</div>
            <h3>Belum ada pendaftaran</h3>
            <p class="muted">
                Kamu belum mendaftar lowongan PKL mana pun.
                Yuk, temukan tempat PKL yang sesuai jurusanmu.
            </p>
            <a href="cari_perusahaan.php" class="primary-link">
                Cari lowongan PKL →
            </a>
        </div>

    <?php else: ?>

        <?php foreach ($pendaftaran as $item): ?>
            <?php
            $status = $item['status'];
            $info = $statusInfo[$status] ?? [
                'label' => ucfirst($status),
                'class' => 'cancelled',
                'icon' => '•',
                'deskripsi' => 'Silakan hubungi pihak sekolah jika memerlukan informasi lebih lanjut.'
            ];
            ?>

            <article class="application-card">
                <div class="application-top">
                    <div class="application-title">
                        <div class="company-initial">
                            <?= e(strtoupper(substr($item['nama_perusahaan'], 0, 1))) ?>
                        </div>

                        <div>
                            <h3><?= e($item['judul_lowongan']) ?></h3>
                            <div class="muted">
                                <?= e($item['nama_perusahaan']) ?>
                                <?php if (!empty($item['kota'])): ?>
                                    · <?= e($item['kota']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <span class="status-badge <?= e($info['class']) ?>">
                        <?= e($info['icon']) ?> <?= e($info['label']) ?>
                    </span>
                </div>

                <div class="application-meta">
                    <span>
                        🎓 Jurusan:
                        <strong><?= e($item['kode_jurusan']) ?></strong>
                    </span>
                    <span>
                        📅 Tanggal daftar:
                        <strong><?= e(tanggalIndonesia($item['tanggal_daftar'])) ?></strong>
                    </span>
                </div>

                <p class="status-description">
                    <?= e($info['deskripsi']) ?>
                </p>

                <?php if (!empty($item['catatan'])): ?>
                    <div class="company-note">
                        <strong>Catatan dari pihak pengelola:</strong><br>
                        <?= nl2br(e($item['catatan'])) ?>
                    </div>
                <?php endif; ?>

                <div class="application-actions">
                    <a
                        href="detail_lowongan.php?id=<?= (int) $item['id_lowongan'] ?>"
                        class="detail-link"
                    >
                        Lihat detail lowongan →
                    </a>
                </div>
            </article>
        <?php endforeach; ?>

    <?php endif; ?>

</main>

</body>
</html>
