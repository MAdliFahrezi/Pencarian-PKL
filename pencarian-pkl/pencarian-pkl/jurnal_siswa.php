
<?php
session_start();
require_once 'koneksi.php';

// Pastikan pengguna sudah login sebagai siswa
if (
    !isset($_SESSION['id_pengguna'], $_SESSION['peran']) ||
    $_SESSION['peran'] !== 'siswa'
) {
    header('Location: login.php');
    exit;
}

// Fungsi untuk mengamankan output HTML
if (!function_exists('e')) {
    function e($nilai): string
    {
        return htmlspecialchars(
            (string)($nilai ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

$idPengguna = (int)$_SESSION['id_pengguna'];
$pesan = '';
$error = '';
$errorDatabase = false;

// Buat token CSRF
if (empty($_SESSION['csrf_jurnal_siswa'])) {
    $_SESSION['csrf_jurnal_siswa'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_jurnal_siswa'];

// Ambil identitas siswa
$stmt = $pdo->prepare("
    SELECT id_siswa, nis
    FROM siswa
    WHERE id_pengguna = ?
    LIMIT 1
");
$stmt->execute([$idPengguna]);
$siswa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$siswa) {
    http_response_code(403);
    exit('Data siswa tidak ditemukan. Periksa hubungan akun dengan tabel siswa.');
}

$idSiswa = (int)$siswa['id_siswa'];

// Nilai awal untuk daftar
$pendaftaran = [];
$jurnal = [];

// Proses penyimpanan jurnal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenPost = $_POST['csrf_token'] ?? '';

    if (
        !is_string($tokenPost) ||
        !hash_equals($csrfToken, $tokenPost)
    ) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman dan coba lagi.');
    }

    $idPendaftaranRaw = $_POST['id_pendaftaran'] ?? '';
    $tanggal = $_POST['tanggal'] ?? '';
    $kegiatan = $_POST['kegiatan'] ?? '';
    $hasil = $_POST['hasil'] ?? '';

    if (
        !is_scalar($idPendaftaranRaw) ||
        !is_string($tanggal) ||
        !is_string($kegiatan) ||
        !is_string($hasil)
    ) {
        $error = 'Format data yang dikirim tidak valid.';
    } else {
        $idPendaftaran = filter_var(
            $idPendaftaranRaw,
            FILTER_VALIDATE_INT
        );

        $kegiatan = trim($kegiatan);
        $hasil = trim($hasil);

        if (
            $idPendaftaran === false ||
            $idPendaftaran === null ||
            $idPendaftaran < 1
        ) {
            $error = 'Pendaftaran PKL tidak valid.';
        } elseif ($tanggal === '' || $kegiatan === '') {
            $error = 'Pendaftaran PKL, tanggal, dan kegiatan wajib diisi.';
        } elseif (
            strlen($kegiatan) > 10000 ||
            strlen($hasil) > 10000
        ) {
            $error = 'Kegiatan dan hasil maksimal 10.000 byte.';
        } elseif (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ||
            !checkdate(
                (int)substr($tanggal, 5, 2),
                (int)substr($tanggal, 8, 2),
                (int)substr($tanggal, 0, 4)
            ) ||
            $tanggal > date('Y-m-d')
        ) {
            $error = 'Tanggal jurnal tidak valid atau tidak boleh di masa depan.';
        } else {
            try {
                // Pastikan pendaftaran diterima dan milik siswa ini
                $cek = $pdo->prepare("
                    SELECT id_pendaftaran
                    FROM pendaftaran
                    WHERE id_pendaftaran = ?
                      AND id_siswa = ?
                      AND status = 'diterima'
                    LIMIT 1
                ");
                $cek->execute([$idPendaftaran, $idSiswa]);

                if (!$cek->fetchColumn()) {
                    $error = 'Pendaftaran PKL tidak valid atau belum diterima.';
                } else {
                    $simpan = $pdo->prepare("
                        INSERT INTO jurnal_pkl (
                            id_pendaftaran,
                            tanggal,
                            kegiatan,
                            hasil,
                            status_verifikasi
                        )
                        VALUES (?, ?, ?, ?, 'menunggu')
                    ");

                    $simpan->execute([
                        $idPendaftaran,
                        $tanggal,
                        $kegiatan,
                        $hasil !== '' ? $hasil : null
                    ]);

                    // Perbarui token setelah berhasil menyimpan
                    $_SESSION['csrf_jurnal_siswa'] =
                        bin2hex(random_bytes(32));

                    header('Location: jurnal_siswa.php?sukses=1');
                    exit;
                }
            } catch (PDOException $ex) {
                error_log(
                    'Kesalahan simpan jurnal siswa: ' . $ex->getMessage()
                );

                $error = 'Jurnal gagal disimpan. Silakan coba lagi.';
                $errorDatabase = true;
            }
        }
    }
}

// Pesan setelah redirect
if (isset($_GET['sukses']) && $_GET['sukses'] === '1') {
    $pesan = 'Jurnal berhasil disimpan!';
}

if (
    isset($_GET['revisi']) &&
    $_GET['revisi'] === 'berhasil'
) {
    $pesan = 'Jurnal berhasil diperbaiki dan diajukan ulang untuk verifikasi.';
}

// Ambil pendaftaran PKL yang diterima oleh siswa ini
try {
    $stmt = $pdo->prepare("
        SELECT
            p.id_pendaftaran,
            l.judul_lowongan,
            pr.nama_perusahaan
        FROM pendaftaran p
        JOIN lowongan_pkl l
            ON p.id_lowongan = l.id_lowongan
        JOIN perusahaan pr
            ON l.id_perusahaan = pr.id_perusahaan
        WHERE p.id_siswa = ?
          AND p.status = 'diterima'
        ORDER BY p.id_pendaftaran DESC
    ");
    $stmt->execute([$idSiswa]);
    $pendaftaran = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil riwayat jurnal milik siswa yang sedang login
    $stmt = $pdo->prepare("
        SELECT
            j.*,
            l.judul_lowongan,
            pr.nama_perusahaan
        FROM jurnal_pkl j
        JOIN pendaftaran p
            ON j.id_pendaftaran = p.id_pendaftaran
        JOIN lowongan_pkl l
            ON p.id_lowongan = l.id_lowongan
        JOIN perusahaan pr
            ON l.id_perusahaan = pr.id_perusahaan
        WHERE p.id_siswa = ?
        ORDER BY j.tanggal DESC, j.id_jurnal DESC
    ");
    $stmt->execute([$idSiswa]);
    $jurnal = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    error_log(
        'Kesalahan memuat jurnal siswa: ' . $ex->getMessage()
    );

    $errorDatabase = true;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jurnal PKL | CariTempatPKL</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #243047;
        }
        header {
            background: #173b70;
            color: white;
            padding: 20px 6%;
        }
        header h2 { margin: 0 0 6px; }
        header p { margin: 0; color: #d9e7ff; }
        main {
            max-width: 1100px;
            margin: 28px auto;
            padding: 0 18px;
        }
        .card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 3px 12px #172b4d12;
        }
        h3 { margin-top: 0; }
        label {
            display: block;
            font-weight: bold;
            margin: 16px 0 7px;
        }
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd5e2;
            border-radius: 7px;
            font: inherit;
        }
        textarea { min-height: 100px; resize: vertical; }
        button {
            margin-top: 18px;
            padding: 12px 20px;
            border: 0;
            border-radius: 7px;
            background: #205bb5;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }
        .alert {
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 16px;
            background: #e1f8e8;
            color: #176534;
        }
        .error { background: #ffe8e8; color: #a52222; }
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 680px;
        }
        th, td {
            padding: 12px;
            border-bottom: 1px solid #e5eaf1;
            text-align: left;
            vertical-align: top;
        }
        th { background: #edf3fc; }
        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #fff1ce;
            color: #875c00;
            font-size: 12px;
            font-weight: bold;
        }
        .disetujui { background: #dcf8e5; color: #176534; }
        .revisi { background: #ffe3e3; color: #a52222; }
        .back { color: white; text-decoration: none; }
        .empty { color: #68768a; padding: 16px 0; }
    </style>
</head>
<body>

<header>
    <h2>Jurnal PKL</h2>
    <p>Catat kegiatan harian selama melaksanakan Praktik Kerja Lapangan.</p>
    <p style="margin-top:12px">
        <a class="back" href="cari_perusahaan.php">← Kembali ke pencarian PKL</a>
    </p>
</header>

<main>
    <section class="card">
        <h3>Tambah Jurnal Kegiatan</h3>

        <?php if ($pesan): ?>
            <div class="alert"><?= e($pesan) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (count($pendaftaran) > 0): ?>
            <form method="POST">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= e($csrfToken) ?>"
    >

    <label for="id_pendaftaran">Tempat PKL</label>
                <select name="id_pendaftaran" id="id_pendaftaran" required>
                    <option value="">-- Pilih tempat PKL --</option>
                    <?php foreach ($pendaftaran as $p): ?>
                        <option value="<?= e($p['id_pendaftaran']) ?>"
                            <?= (($_POST['id_pendaftaran'] ?? '') == $p['id_pendaftaran']) ? 'selected' : '' ?>>
                            <?= e($p['nama_perusahaan']) ?> —
                            <?= e($p['judul_lowongan']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="tanggal">Tanggal Kegiatan</label>
                <input
                    type="date"
                    name="tanggal"
                    id="tanggal"
                    max="<?= date('Y-m-d') ?>"
                    value="<?= e($_POST['tanggal'] ?? date('Y-m-d')) ?>"
                    required
                >

                <label for="kegiatan">Kegiatan yang Dilakukan</label>
                <textarea
    name="kegiatan"
    id="kegiatan"
    maxlength="10000"
    placeholder="Contoh: Membuat tampilan halaman login menggunakan HTML dan CSS."
    required><?= e($_POST['kegiatan'] ?? '') ?></textarea>

                <label for="hasil">Hasil Kegiatan</label>
                <textarea
    name="hasil"
    id="hasil"
    maxlength="10000"
    placeholder="Contoh: Halaman login berhasil dibuat dan tampil dengan baik."><?= e($_POST['hasil'] ?? '') ?></textarea>

                <button type="submit">Simpan Jurnal</button>
            </form>
        <?php else: ?>
            <p class="empty">
                Kamu belum memiliki pendaftaran PKL berstatus diterima.
                Jurnal dapat diisi setelah pendaftaran PKL diterima.
            </p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3>Riwayat Jurnal Saya</h3>

        <?php if (count($jurnal) > 0): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Tempat PKL</th>
                            <th>Kegiatan</th>
                            <th>Hasil</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($jurnal as $j): ?>
                        <tr>
                            <td><?= e(date('d-m-Y', strtotime($j['tanggal']))) ?></td>
                            <td>
                                <strong><?= e($j['nama_perusahaan']) ?></strong><br>
                                <?= e($j['judul_lowongan']) ?>
                            </td>
                            <td><?= nl2br(e($j['kegiatan'])) ?></td>
                            <td><?= nl2br(e($j['hasil'] ?? '-')) ?></td>
                            <td>
                                <?php
                                $kelas = [
                                    'disetujui' => 'disetujui',
                                    'perlu_revisi' => 'revisi'
                                ][$j['status_verifikasi']] ?? '';
                                ?>
                                <span class="status <?= e($kelas) ?>">
                                    <?= e(ucwords(str_replace('_', ' ', $j['status_verifikasi']))) ?>
                                </span>
                                
<?php if ($j['status_verifikasi'] === 'perlu_revisi'): ?>
    <p>
        <a href="edit_jurnal.php?id=<?= (int) $j['id_jurnal'] ?>"
           style="display:inline-block;padding:7px 10px;
                  background:#205bb5;color:white;
                  text-decoration:none;border-radius:6px;">
            Edit Jurnal
        </a>
    </p>
<?php endif; ?>

                                
<?php if (!empty($j['catatan_verifikasi'])): ?>
    <p style="margin-top:8px; color:#a52222; font-size:13px;">
        <strong>Catatan:</strong><br>
        <?= nl2br(e($j['catatan_verifikasi'])) ?>
    </p>
<?php endif; ?>

                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">Belum ada jurnal yang dicatat.</p>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
