
<?php
session_start();
require_once 'koneksi.php';

/* =====================================
   1. PEMERIKSAAN AKSES
===================================== */
if (
    !isset($_SESSION['id_pengguna']) ||
    !isset($_SESSION['peran']) ||
    !in_array(
        $_SESSION['peran'],
        ['admin', 'guru', 'koordinator'],
        true
    )
) {
    header('Location: login.php');
    exit;
}

/* =====================================
   2. FUNGSI KEAMANAN OUTPUT
===================================== */
function e($nilai): string
{
    return htmlspecialchars(
        (string) $nilai,
        ENT_QUOTES,
        'UTF-8'
    );
}

$pesan = '';
$error = '';

/* =====================================
   3. TOKEN CSRF
===================================== */
if (
    empty($_SESSION['csrf_monitoring_jurnal']) ||
    !is_string($_SESSION['csrf_monitoring_jurnal'])
) {
    $_SESSION['csrf_monitoring_jurnal'] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_monitoring_jurnal'];

/* =====================================
   4. PROSES VERIFIKASI JURNAL
===================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $idRaw = $_POST['id_jurnal'] ?? '';
    $statusRaw = $_POST['status_verifikasi'] ?? '';
    $catatanRaw = $_POST['catatan_verifikasi'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($csrf, $token)
    ) {
        $error = 'Permintaan tidak valid. Muat ulang halaman dan coba kembali.';
    } elseif (
        !is_string($idRaw) ||
        !is_string($statusRaw) ||
        !is_string($catatanRaw)
    ) {
        $error = 'Format data yang dikirim tidak valid.';
    } else {
        $idJurnal = filter_var(
            $idRaw,
            FILTER_VALIDATE_INT
        );

        $status = $statusRaw;
        $catatan = trim($catatanRaw);

        $statusValid = [
            'disetujui',
            'perlu_revisi'
        ];

        if (
            $idJurnal === false ||
            $idJurnal === null ||
            $idJurnal < 1 ||
            !in_array($status, $statusValid, true)
        ) {
            $error = 'Data verifikasi tidak valid.';
        } elseif (
            $status === 'perlu_revisi' &&
            $catatan === ''
        ) {
            $error = 'Alasan revisi wajib diisi.';
        } elseif (strlen($catatan) > 10000) {
            $error = 'Catatan verifikasi terlalu panjang.';
        } else {
            try {
                $pdo->beginTransaction();

                /*
                 * Kunci baris jurnal selama verifikasi.
                 */
                $cek = $pdo->prepare(
                    "SELECT id_jurnal
                     FROM jurnal_pkl
                     WHERE id_jurnal = ?
                     FOR UPDATE"
                );

                $cek->execute([$idJurnal]);
                $jurnalAda = $cek->fetchColumn();

                if (!$jurnalAda) {
                    $pdo->rollBack();
                    $error = 'Jurnal tidak ditemukan.';
                } else {
                    if ($status === 'disetujui') {
                        $catatan = '';
                    }

                    $update = $pdo->prepare(
                        "UPDATE jurnal_pkl
                         SET status_verifikasi = ?,
                             catatan_verifikasi = ?
                         WHERE id_jurnal = ?"
                    );

                    $update->execute([
    $status,
    $catatan !== '' ? $catatan : null,
    $idJurnal
]);

/*
 * Ambil ID pengguna siswa yang memiliki jurnal ini.
 */
$stmtSiswa = $pdo->prepare("
    SELECT
        pd.id_pendaftaran,
        s.id_pengguna,
        pg.nama_lengkap
    FROM jurnal_pkl j
    JOIN pendaftaran pd
        ON j.id_pendaftaran = pd.id_pendaftaran
    JOIN siswa s
        ON pd.id_siswa = s.id_siswa
    JOIN pengguna pg
        ON s.id_pengguna = pg.id_pengguna
    WHERE j.id_jurnal = ?
");

$stmtSiswa->execute([$idJurnal]);
$dataSiswa = $stmtSiswa->fetch(PDO::FETCH_ASSOC);

if ($dataSiswa) {
    if ($status === 'disetujui') {
        $judulNotifikasi = 'Jurnal PKL Disetujui';
        $isiNotifikasi = 'Jurnal PKL tanggal ' .
            date('d-m-Y') .
            ' telah disetujui oleh guru.';
    } else {
        $judulNotifikasi = 'Jurnal PKL Perlu Revisi';
        $isiNotifikasi = 'Jurnal PKL kamu perlu diperbaiki. ' .
            'Catatan guru: ' . $catatan;
    }

    $stmtNotifikasi = $pdo->prepare("
        INSERT INTO notifikasi (
            id_pengguna,
            id_pendaftaran,
            judul,
            pesan,
            dibaca
        ) VALUES (?, ?, ?, ?, 0)
    ");

    $stmtNotifikasi->execute([
        $dataSiswa['id_pengguna'],
        $dataSiswa['id_pendaftaran'],
        $judulNotifikasi,
        $isiNotifikasi
    ]);
}

$pdo->commit();

                    /*
                     * Buat token baru setelah berhasil.
                     */
                    $_SESSION['csrf_monitoring_jurnal'] =
                        bin2hex(random_bytes(32));

                    header(
                        'Location: monitoring_jurnal.php?status=' .
                        urlencode($status)
                    );
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    '[monitoring_jurnal.php] Gagal verifikasi: ' .
                    $e->getMessage()
                );

                $error = 'Terjadi kesalahan saat memverifikasi jurnal. Silakan coba kembali.';
            }
        }
    }
}

/* =====================================
   5. PESAN HASIL VERIFIKASI
===================================== */
$statusPesan = $_GET['status'] ?? '';

if (is_string($statusPesan)) {
    if ($statusPesan === 'disetujui') {
        $pesan = 'Jurnal berhasil disetujui.';
    } elseif ($statusPesan === 'perlu_revisi') {
        $pesan = 'Jurnal ditandai perlu revisi.';
    }
}

/* =====================================
   6. FILTER STATUS
===================================== */
$filterRaw = $_GET['filter'] ?? '';

$filter = is_string($filterRaw)
    ? $filterRaw
    : '';

$statusFilterValid = [
    'menunggu',
    'disetujui',
    'perlu_revisi'
];

if (!in_array($filter, $statusFilterValid, true)) {
    $filter = '';
}

/* =====================================
   7. AMBIL DATA JURNAL
===================================== */
$jurnal = [];

try {
    $sql = "
        SELECT
            j.id_jurnal,
            j.tanggal,
            j.kegiatan,
            j.hasil,
            j.status_verifikasi,
            j.catatan_verifikasi,
            s.nis,
            pg.nama_lengkap,
            jur.nama_jurusan,
            l.judul_lowongan,
            pr.nama_perusahaan
        FROM jurnal_pkl j
        JOIN pendaftaran pd
            ON j.id_pendaftaran = pd.id_pendaftaran
        JOIN siswa s
            ON pd.id_siswa = s.id_siswa
        JOIN pengguna pg
            ON s.id_pengguna = pg.id_pengguna
        JOIN jurusan jur
            ON s.id_jurusan = jur.id_jurusan
        JOIN lowongan_pkl l
            ON pd.id_lowongan = l.id_lowongan
        JOIN perusahaan pr
            ON l.id_perusahaan = pr.id_perusahaan
    ";

    $params = [];

    if ($filter !== '') {
        $sql .= " WHERE j.status_verifikasi = ?";
        $params[] = $filter;
    }

    $sql .= "
        ORDER BY
            CASE j.status_verifikasi
                WHEN 'menunggu' THEN 1
                WHEN 'perlu_revisi' THEN 2
                ELSE 3
            END,
            j.tanggal DESC,
            j.id_jurnal DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $jurnal = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log(
        '[monitoring_jurnal.php] Gagal mengambil jurnal: ' .
        $e->getMessage()
    );

    $error = 'Data jurnal gagal dimuat. Silakan coba kembali.';
}

/* =====================================
   8. HITUNG STATISTIK JURNAL
===================================== */
$jumlah = [
    'menunggu' => 0,
    'disetujui' => 0,
    'perlu_revisi' => 0
];

try {
    $stmt = $pdo->query("
        SELECT
            status_verifikasi,
            COUNT(*) AS total
        FROM jurnal_pkl
        GROUP BY status_verifikasi
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $statusRow = $row['status_verifikasi'];

        if (isset($jumlah[$statusRow])) {
            $jumlah[$statusRow] = (int) $row['total'];
        }
    }

} catch (Throwable $e) {
    error_log(
        '[monitoring_jurnal.php] Gagal menghitung statistik: ' .
        $e->getMessage()
    );

    $error = 'Statistik jurnal gagal dimuat.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monitoring Jurnal | CariTempatPKL</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #26344b;
        }

        header {
            background: #173b70;
            color: white;
            padding: 22px 5%;
        }

        header h2 { margin: 0 0 7px; }
        header p { margin: 0; color: #d9e7ff; }

        main {
            max-width: 1400px;
            margin: 28px auto;
            padding: 0 18px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px #172b4d10;
        }

        .stat-number {
            font-size: 30px;
            font-weight: bold;
            margin-top: 8px;
        }

        .muted { color: #718096; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th, td {
            padding: 13px;
            border-bottom: 1px solid #e5eaf1;
            text-align: left;
            vertical-align: top;
        }

        th { background: #edf3fc; }

        .badge {
            display: inline-block;
            padding: 6px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            background: #fff1ce;
            color: #875c00;
        }

        .badge.disetujui {
            background: #dcf8e5;
            color: #176534;
        }

        .badge.perlu_revisi {
            background: #ffe3e3;
            color: #a52222;
        }

        select, textarea {
            width: 100%;
            padding: 9px;
            border: 1px solid #ccd5e2;
            border-radius: 6px;
            margin-bottom: 8px;
            font: inherit;
        }

        textarea { min-height: 65px; }

        button, .btn {
            display: inline-block;
            border: 0;
            padding: 10px 13px;
            border-radius: 6px;
            background: #205bb5;
            color: white;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
        }

        .filter {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .filter select { width: auto; min-width: 190px; margin: 0; }

        .alert {
            padding: 13px;
            border-radius: 7px;
            background: #dcf8e5;
            color: #176534;
            margin-bottom: 18px;
        }

        .alert.error {
            background: #ffe3e3;
            color: #a52222;
        }

        @media (max-width: 650px) {
            .cards { grid-template-columns: 1fr; }
        }

        @media print {
            header .back, .filter, .action, .no-print { display: none !important; }
            body { background: white; }
            main { max-width: none; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

<header>
    <h2>Monitoring Jurnal PKL</h2>
    <p>Pantau dan verifikasi jurnal kegiatan siswa.</p>
    <p class="back" style="margin-top:12px">
        
<?php
switch ($_SESSION['peran']) {
    case 'koordinator':
        $dashboardKembali = 'dashboard_koordinator.php';
        $teksKembali = '← Kembali ke Dashboard Koordinator';
        break;

    case 'guru':
        $dashboardKembali = 'dashboard_guru.php';
        $teksKembali = '← Kembali ke Dashboard Guru';
        break;

    default:
        $dashboardKembali = 'dashboard_admin.php';
        $teksKembali = '← Kembali ke Dashboard Admin';
        break;
}
?>


<a href="<?= htmlspecialchars($dashboardKembali) ?>"
   style="color: #ffffff;
          text-decoration: underline;
          font-weight: bold;
          font-size: 16px;">
    <?= htmlspecialchars($teksKembali) ?>
</a>


    </p>
</header>

<main>
    <?php if ($pesan): ?>
        <div class="alert"><?= e($pesan) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="cards">
        <div class="card">
            <div class="muted">Menunggu Verifikasi</div>
            <div class="stat-number"><?= $jumlah['menunggu'] ?></div>
        </div>

        <div class="card">
            <div class="muted">Disetujui</div>
            <div class="stat-number"><?= $jumlah['disetujui'] ?></div>
        </div>

        <div class="card">
            <div class="muted">Perlu Revisi</div>
            <div class="stat-number"><?= $jumlah['perlu_revisi'] ?></div>
        </div>
    </div>

    <section class="card">
        <h3>Daftar Jurnal Siswa</h3>

        <form method="GET" class="filter">
            <select name="filter">
                <option value="">Semua Status</option>
                <option value="menunggu"
                    <?= $filter === 'menunggu' ? 'selected' : '' ?>>
                    Menunggu
                </option>
                <option value="disetujui"
                    <?= $filter === 'disetujui' ? 'selected' : '' ?>>
                    Disetujui
                </option>
                <option value="perlu_revisi"
                    <?= $filter === 'perlu_revisi' ? 'selected' : '' ?>>
                    Perlu Revisi
                </option>
            </select>
            <button type="submit">Filter</button>
            <a href="monitoring_jurnal.php" class="btn">Reset</a>
            <button type="button" onclick="window.print()">Cetak</button>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Siswa</th>
                        <th>Tempat PKL</th>
                        <th>Kegiatan dan Hasil</th>
                        <th>Status</th>
                        <th class="action">Verifikasi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$jurnal): ?>
                    <tr>
                        <td colspan="6">Belum ada jurnal yang tercatat.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($jurnal as $j): ?>
                        <tr>
                            <td><?= e(date('d-m-Y', strtotime($j['tanggal']))) ?></td>
                            <td>
                                <strong><?= e($j['nama_lengkap']) ?></strong><br>
                                NIS: <?= e($j['nis']) ?><br>
                                <span class="muted"><?= e($j['nama_jurusan']) ?></span>
                            </td>
                            <td>
                                <strong><?= e($j['nama_perusahaan']) ?></strong><br>
                                <?= e($j['judul_lowongan']) ?>
                            </td>
                            <td>
                                <strong>Kegiatan:</strong><br>
                                <?= nl2br(e($j['kegiatan'])) ?>
                                <br><br>
                                <strong>Hasil:</strong><br>
                                <?= nl2br(e($j['hasil'] ?? '-')) ?>
                            </td>
                            <td>
    <span class="badge <?= e($j['status_verifikasi']) ?>">
        <?= e(ucwords(str_replace(
            '_',
            ' ',
            $j['status_verifikasi']
        ))) ?>
    </span>

    <?php if (!empty($j['catatan_verifikasi'])): ?>
        <br><br>
        <strong>Catatan Guru:</strong><br>
        <?= nl2br(e($j['catatan_verifikasi'])) ?>
    <?php endif; ?>
</td>
                            <td class="action">
    <form method="POST">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrf) ?>"
        >

        <input
            type="hidden"
            name="id_jurnal"
            value="<?= (int) $j['id_jurnal'] ?>"
        >

        <select name="status_verifikasi" required>
            <option value="disetujui">Disetujui</option>
            <option value="perlu_revisi">Perlu Revisi</option>
        </select>

        <textarea
            name="catatan_verifikasi"
            maxlength="10000"
            placeholder="Tulis alasan revisi jika diperlukan..."
        ></textarea>

        <button
            type="submit"
            onclick="return confirm('Simpan verifikasi jurnal ini?')"
        >
            Simpan Status
        </button>
    </form>
</td>
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
