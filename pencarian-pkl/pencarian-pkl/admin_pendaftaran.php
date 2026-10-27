
<?php
session_start();
require_once 'koneksi.php';

if (!isset($_SESSION['id_pengguna']) || !isset($_SESSION['peran'])) {
    header('Location: login.php');
    exit;
}

if (!in_array($_SESSION['peran'], ['admin', 'koordinator', 'guru', 'perusahaan'], true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$peranLogin = $_SESSION['peran'];
$idPenggunaLogin = (int) $_SESSION['id_pengguna'];

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$pesan = '';
$jenisPesan = 'success';

// Token CSRF untuk melindungi form perubahan status.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenDikirim = $_POST['csrf_token'] ?? '';

    if (
        !is_string($tokenDikirim) ||
        !isset($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $tokenDikirim)
    ) {
        $pesan = 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.';
        $jenisPesan = 'error';
    } else {
        $idPendaftaran = filter_input(
            INPUT_POST,
            'id_pendaftaran',
            FILTER_VALIDATE_INT
        );

        $statusInput = $_POST['status'] ?? '';
        $catatanInput = $_POST['catatan'] ?? '';

        $statusValid = ['diproses', 'diterima', 'ditolak'];

        if (
            !$idPendaftaran ||
            $idPendaftaran < 1 ||
            !is_string($statusInput) ||
            !in_array($statusInput, $statusValid, true) ||
            !is_string($catatanInput)
        ) {
            $pesan = 'Data yang dikirim tidak valid.';
            $jenisPesan = 'error';
        } else {
            $statusBaru = $statusInput;
            $catatan = trim($catatanInput);

            // Batasi panjang catatan agar input tetap terkendali.
            if (strlen($catatan) > 5000) {
                $pesan = 'Catatan terlalu panjang. Maksimal 5000 byte.';
                $jenisPesan = 'error';
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
    SELECT
        pd.id_pendaftaran,
        pd.id_lowongan,
        pd.status
    FROM pendaftaran pd
    JOIN lowongan_pkl l ON l.id_lowongan = pd.id_lowongan
    JOIN perusahaan pr ON pr.id_perusahaan = l.id_perusahaan
    WHERE pd.id_pendaftaran = ?
      AND (
          ? != 'perusahaan'
          OR pr.id_pengguna = ?
      )
    FOR UPDATE
");

$stmt->execute([
    $idPendaftaran,
    $peranLogin,
    $idPenggunaLogin
]);

$data = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$data) {
                        throw new RuntimeException(
                            'Pendaftaran tidak ditemukan.'
                        );
                    }

                    // Kunci lowongan untuk mencegah penerimaan melebihi kuota
                    // akibat dua proses penerimaan yang berjalan bersamaan.
                    $stmt = $pdo->prepare("
                        SELECT id_lowongan, kuota
                        FROM lowongan_pkl
                        WHERE id_lowongan = ?
                        FOR UPDATE
                    ");
                    $stmt->execute([$data['id_lowongan']]);
                    $lowongan = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$lowongan) {
                        throw new RuntimeException(
                            'Lowongan PKL tidak ditemukan.'
                        );
                    }

                    // Status ditolak dan dibatalkan tidak dapat diubah.
                    if (
                        in_array(
                            $data['status'],
                            ['ditolak', 'dibatalkan'],
                            true
                        )
                    ) {
                        throw new RuntimeException(
                            'Pendaftaran yang sudah ditolak atau dibatalkan tidak dapat diubah.'
                        );
                    }

                    // Periksa kuota saat mengubah status menjadi diterima.
                    if (
                        $statusBaru === 'diterima' &&
                        $data['status'] !== 'diterima'
                    ) {
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*)
                            FROM pendaftaran
                            WHERE id_lowongan = ?
                              AND status = 'diterima'
                        ");
                        $stmt->execute([$data['id_lowongan']]);

                        $jumlahDiterima = (int) $stmt->fetchColumn();

                        if (
                            $jumlahDiterima >= (int) $lowongan['kuota']
                        ) {
                            throw new RuntimeException(
                                'Kuota lowongan sudah penuh.'
                            );
                        }
                    }

                    // Simpan perubahan menggunakan prepared statement.
                    $stmt = $pdo->prepare("
                        UPDATE pendaftaran
                        SET status = ?, catatan = ?
                        WHERE id_pendaftaran = ?
                    ");

                    $stmt->execute([
    $statusBaru,
    $catatan !== '' ? $catatan : null,
    $idPendaftaran
]);

// Kirim notifikasi kepada siswa mengenai perubahan status.
if ($statusBaru !== $data['status']) {
    $stmt = $pdo->prepare("
        SELECT
            s.id_pengguna,
            u.nama_lengkap,
            l.judul_lowongan,
            pr.nama_perusahaan
        FROM pendaftaran pd
        JOIN siswa s ON s.id_siswa = pd.id_siswa
        JOIN pengguna u ON u.id_pengguna = s.id_pengguna
        JOIN lowongan_pkl l ON l.id_lowongan = pd.id_lowongan
        JOIN perusahaan pr ON pr.id_perusahaan = l.id_perusahaan
        WHERE pd.id_pendaftaran = ?
    ");
    $stmt->execute([$idPendaftaran]);
    $detail = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($detail) {
        if ($statusBaru === 'diterima') {
            $judulNotifikasi = 'Selamat! Pendaftaran PKL Diterima';
            $pesanNotifikasi = 'Pendaftaran PKL kamu pada ' .
                $detail['nama_perusahaan'] .
                ' untuk lowongan "' . $detail['judul_lowongan'] .
                '" telah diterima.';
        } elseif ($statusBaru === 'ditolak') {
            $judulNotifikasi = 'Informasi Hasil Pendaftaran PKL';
            $pesanNotifikasi = 'Pendaftaran PKL kamu pada ' .
                $detail['nama_perusahaan'] .
                ' untuk lowongan "' . $detail['judul_lowongan'] .
                '" ditolak.';
        } else {
            $judulNotifikasi = 'Status Pendaftaran PKL Diperbarui';
            $pesanNotifikasi = 'Status pendaftaran PKL kamu pada ' .
                $detail['nama_perusahaan'] .
                ' untuk lowongan "' . $detail['judul_lowongan'] .
                '" berubah menjadi ' . $statusBaru . '.';
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifikasi
                (id_pengguna, id_pendaftaran, judul, pesan, dibaca, dibuat_pada)
            VALUES (?, ?, ?, ?, 0, NOW())
        ");

        $stmt->execute([
            $detail['id_pengguna'],
            $idPendaftaran,
            $judulNotifikasi,
            $pesanNotifikasi
        ]);
    }
}

$pdo->commit();
                    $pesan = 'Status pendaftaran berhasil diperbarui.';
                    $jenisPesan = 'success';

                } catch (RuntimeException $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $pesan = $ex->getMessage();
                    $jenisPesan = 'error';

                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        'Gagal memperbarui pendaftaran: ' . $ex->getMessage()
                    );

                    $pesan = 'Gagal memperbarui data. Silakan coba lagi.';
                    $jenisPesan = 'error';
                }
            }
        }
    }
}


$filter = $_GET['status'] ?? '';
$statusFilterValid = [
    '', 'menunggu', 'diproses', 'diterima', 'ditolak', 'dibatalkan'
];

if (!in_array($filter, $statusFilterValid, true)) {
    $filter = '';
}

$sql = "
    SELECT
        pd.id_pendaftaran,
        pd.tanggal_daftar,
        pd.status,
        pd.catatan,
        s.nis,
        s.kelas,
        u.nama_lengkap,
        j.kode_jurusan,
        j.nama_jurusan,
        l.judul_lowongan,
        l.id_lowongan,
        pr.nama_perusahaan
    FROM pendaftaran pd
    JOIN siswa s ON s.id_siswa = pd.id_siswa
    JOIN pengguna u ON u.id_pengguna = s.id_pengguna
    JOIN jurusan j ON j.id_jurusan = s.id_jurusan
    JOIN lowongan_pkl l ON l.id_lowongan = pd.id_lowongan
    JOIN perusahaan pr ON pr.id_perusahaan = l.id_perusahaan
";

$params = [];
$conditions = [];

if ($peranLogin === 'perusahaan') {
    $conditions[] = 'pr.id_pengguna = ?';
    $params[] = $idPenggunaLogin;
}

if ($filter !== '') {
    $conditions[] = 'pd.status = ?';
    $params[] = $filter;
}

if (!empty($conditions)) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= " ORDER BY pd.tanggal_daftar DESC, pd.id_pendaftaran DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daftar = $stmt->fetchAll();


$sqlRingkasan = "
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(pd.status = 'menunggu'), 0) AS menunggu,
        COALESCE(SUM(pd.status = 'diproses'), 0) AS diproses,
        COALESCE(SUM(pd.status = 'diterima'), 0) AS diterima,
        COALESCE(SUM(pd.status = 'ditolak'), 0) AS ditolak
    FROM pendaftaran pd
    JOIN lowongan_pkl l ON l.id_lowongan = pd.id_lowongan
    JOIN perusahaan pr ON pr.id_perusahaan = l.id_perusahaan
";

$paramsRingkasan = [];

if ($peranLogin === 'perusahaan') {
    $sqlRingkasan .= " WHERE pr.id_pengguna = ?";
    $paramsRingkasan[] = $idPenggunaLogin;
}

$stmt = $pdo->prepare($sqlRingkasan);
$stmt->execute($paramsRingkasan);
$ringkasan = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kelola Pendaftaran - CariTempatPKL</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
body{background:#f4f7fd;color:#172744}
.admin-wrap{max-width:1200px;margin:35px auto;padding:0 22px}
.admin-hero{background:linear-gradient(120deg,#1944b5,#3470f2);color:white;padding:30px;border-radius:20px;margin-bottom:24px}
.admin-hero h1{margin:8px 0;font-size:30px}
.admin-hero p{margin:0;color:#e6edff;line-height:1.6}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:25px}
.stat,.panel{background:white;border:1px solid #e4eaf4;border-radius:16px;padding:20px}
.stat span{color:#71809c;font-size:13px}
.stat strong{display:block;font-size:27px;margin-top:8px}
.panel{margin-bottom:20px}
.panel h2{font-size:19px;margin:0 0 16px}
.filter{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
select,input,textarea{font:inherit;padding:10px;border:1px solid #d9e1ef;border-radius:9px;max-width:100%}
.btn{display:inline-block;border:0;background:#2c5ce5;color:white;padding:11px 15px;border-radius:9px;text-decoration:none;font-weight:700;cursor:pointer}
.btn:hover{background:#1948c7}
.btn:disabled{opacity:.5;cursor:not-allowed}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:850px}
th,td{text-align:left;padding:13px 12px;border-bottom:1px solid #edf0f6;vertical-align:top;font-size:13px}
th{color:#71809c;background:#f8faff}
td strong{font-size:14px}
.badge{display:inline-block;padding:6px 9px;border-radius:20px;font-size:11px;font-weight:800}
.menunggu{background:#fff3d7;color:#986300}
.diproses{background:#e7efff;color:#2457c5}
.diterima{background:#e2f8ea;color:#18723a}
.ditolak,.dibatalkan{background:#ffe7e7;color:#b42323}
.note{width:190px;min-height:65px;box-sizing:border-box}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px}
.alert.success{background:#e2f8ea;color:#18723a}
.alert.error{background:#ffe7e7;color:#b42323}
small{color:#71809c}
.back-navigation {
    margin: -8px 0 24px;
}

.back-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 16px;
    background: #ffffff;
    color: #2454d8;
    border: 1px solid #dbe4f0;
    border-radius: 10px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
    transition: background 0.2s, color 0.2s;
}

.back-button:hover {
    background: #2454d8;
    color: #ffffff;
}
@media(max-width:700px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.admin-wrap{padding:0 13px}.admin-hero{padding:23px}.admin-hero h1{font-size:25px}}
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
            <strong><?= e($_SESSION['nama_lengkap'] ?? 'Pengelola') ?></strong>
            <a href="logout.php" class="logout-link">Keluar</a>
        </div>
    </div>
</header>

<main class="admin-wrap">
    <section class="admin-hero">
        <small style="color:#e6edff">PANEL PENGELOLA</small>
        <h1>Kelola Pendaftaran PKL</h1>
        <p>Periksa pendaftaran siswa dan perbarui statusnya.</p>
    </section>

    <div class="back-navigation">
        <a href="dashboard_admin.php" class="back-button">
            &larr; Kembali ke Dashboard Admin
        </a>
    </div>
    <?php if ($pesan !== ''): ?>
        <div class="alert <?= e($jenisPesan) ?>"><?= e($pesan) ?></div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat"><span>Total pendaftaran</span><strong><?= (int)($ringkasan['total'] ?? 0) ?></strong></div>
        <div class="stat"><span>Menunggu</span><strong><?= (int)($ringkasan['menunggu'] ?? 0) ?></strong></div>
        <div class="stat"><span>Diproses</span><strong><?= (int)($ringkasan['diproses'] ?? 0) ?></strong></div>
        <div class="stat"><span>Diterima</span><strong><?= (int)($ringkasan['diterima'] ?? 0) ?></strong></div>
    </section>

    <section class="panel">
        <h2>Daftar pendaftaran siswa</h2>
        <form method="GET" class="filter">
            <label for="status">Filter status:</label>
            <select name="status" id="status">
                <option value="">Semua status</option>
                <?php foreach (['menunggu','diproses','diterima','ditolak','dibatalkan'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $filter === $s ? 'selected' : '' ?>>
                        <?= e(ucfirst($s)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Terapkan</button>
        </form>
    </section>

    <section class="panel">
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Siswa</th>
                    <th>Lowongan</th>
                    <th>Tanggal daftar</th>
                    <th>Status saat ini</th>
                    <th>Ubah status dan catatan</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$daftar): ?>
                <tr><td colspan="5">Belum ada data pendaftaran.</td></tr>
            <?php else: ?>
                <?php foreach ($daftar as $d): ?>
                <tr>
                    <td>
                        <strong><?= e($d['nama_lengkap']) ?></strong><br>
                        <small>NIS: <?= e($d['nis']) ?></small><br>
                        <small><?= e($d['kode_jurusan']) ?> · <?= e($d['kelas']) ?></small>
                    </td>
                    <td>
                        <strong><?= e($d['judul_lowongan']) ?></strong><br>
                        <small><?= e($d['nama_perusahaan']) ?></small>
                    </td>
                    <td><?= e($d['tanggal_daftar']) ?></td>
                    <td>
                        <span class="badge <?= e($d['status']) ?>">
                            <?= e(ucfirst($d['status'])) ?>
                        </span>
                    </td>
                    <td>
                    <?php if (in_array($d['status'], ['ditolak','dibatalkan'], true)): ?>
                        <small>Status ini tidak dapat diubah.</small>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="id_pendaftaran" value="<?= (int)$d['id_pendaftaran'] ?>">
                            <select name="status" required>
                                <option value="diproses" <?= $d['status'] === 'diproses' ? 'selected' : '' ?>>Diproses</option>
                                <option value="diterima" <?= $d['status'] === 'diterima' ? 'selected' : '' ?>>Diterima</option>
                                <option value="ditolak">Ditolak</option>
                            </select>
                            <br><br>
                            <textarea class="note" name="catatan" placeholder="Catatan untuk siswa..."><?= e($d['catatan']) ?></textarea>
                            <br><br>
                            <button class="btn" type="submit">Simpan perubahan</button>
                        </form>
                    <?php endif; ?>
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
