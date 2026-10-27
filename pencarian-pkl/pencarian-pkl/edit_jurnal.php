
<?php
session_start();
require_once 'koneksi.php';

// Pastikan pengguna login sebagai siswa
if (
    !isset($_SESSION['id_pengguna'], $_SESSION['peran']) ||
    $_SESSION['peran'] !== 'siswa'
) {
    header('Location: login.php');
    exit;
}

// Amankan output HTML
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

$idPengguna = (int) $_SESSION['id_pengguna'];

// Buat token CSRF
if (empty($_SESSION['csrf_edit_jurnal'])) {
    $_SESSION['csrf_edit_jurnal'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_edit_jurnal'];

$idJurnal = filter_var(
    $_GET['id'] ?? $_POST['id_jurnal'] ?? 0,
    FILTER_VALIDATE_INT
);

if (!$idJurnal || $idJurnal < 1) {
    exit('ID jurnal tidak valid.');
}

// Ambil identitas siswa
$stmt = $pdo->prepare("
    SELECT id_siswa
    FROM siswa
    WHERE id_pengguna = ?
    LIMIT 1
");
$stmt->execute([$idPengguna]);
$siswa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$siswa) {
    exit('Data siswa tidak ditemukan.');
}

$idSiswa = (int) $siswa['id_siswa'];

// Pastikan jurnal milik siswa yang sedang login
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
    WHERE j.id_jurnal = ?
      AND p.id_siswa = ?
    LIMIT 1
");
$stmt->execute([$idJurnal, $idSiswa]);
$jurnal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$jurnal) {
    exit('Jurnal tidak ditemukan atau bukan milik akun kamu.');
}

// Hanya jurnal yang perlu revisi yang boleh diedit
if ($jurnal['status_verifikasi'] !== 'perlu_revisi') {
    exit(
        'Jurnal hanya dapat diedit jika statusnya Perlu Revisi. ' .
        '<a href="jurnal_siswa.php">Kembali ke jurnal</a>'
    );
}

$error = '';

// PROSES FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validasi CSRF hanya ketika formulir dikirim
    $tokenPost = $_POST['csrf_token'] ?? '';

    if (
        !is_string($tokenPost) ||
        !hash_equals($csrfToken, $tokenPost)
    ) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman dan coba lagi.');
    }

    $tanggal = $_POST['tanggal'] ?? '';
    $kegiatan = $_POST['kegiatan'] ?? '';
    $hasil = $_POST['hasil'] ?? '';

    // Pastikan data berupa teks
    if (
        !is_string($tanggal) ||
        !is_string($kegiatan) ||
        !is_string($hasil)
    ) {
        $error = 'Format data yang dikirim tidak valid.';
    } else {
        $kegiatan = trim($kegiatan);
        $hasil = trim($hasil);

        // Validasi data
        if ($tanggal === '' || $kegiatan === '') {
            $error = 'Tanggal dan kegiatan wajib diisi.';
        } elseif (
            strlen($kegiatan) > 10000 ||
            strlen($hasil) > 10000
        ) {
            $error = 'Kegiatan dan hasil maksimal 10.000 byte.';
        } elseif (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ||
            !checkdate(
                (int) substr($tanggal, 5, 2),
                (int) substr($tanggal, 8, 2),
                (int) substr($tanggal, 0, 4)
            ) ||
            $tanggal > date('Y-m-d')
        ) {
            $error = 'Tanggal kegiatan tidak valid atau tidak boleh di masa depan.';
        } else {
            try {
                // Update hanya jika jurnal masih perlu revisi
                // dan benar-benar milik siswa yang sedang login.
                $update = $pdo->prepare("
                    UPDATE jurnal_pkl
                    SET tanggal = ?,
                        kegiatan = ?,
                        hasil = ?,
                        status_verifikasi = 'menunggu',
                        catatan_verifikasi = NULL
                    WHERE id_jurnal = ?
                      AND status_verifikasi = 'perlu_revisi'
                      AND id_pendaftaran IN (
                          SELECT id_pendaftaran
                          FROM pendaftaran
                          WHERE id_siswa = ?
                      )
                ");

                $update->execute([
                    $tanggal,
                    $kegiatan,
                    $hasil !== '' ? $hasil : null,
                    $idJurnal,
                    $idSiswa
                ]);

                if ($update->rowCount() > 0) {
                    // Perbarui token setelah berhasil menyimpan
                    $_SESSION['csrf_edit_jurnal'] =
                        bin2hex(random_bytes(32));

                    header(
                        'Location: jurnal_siswa.php?revisi=berhasil'
                    );
                    exit;
                }

                $error = 'Jurnal tidak dapat diperbarui. Muat ulang halaman dan coba lagi.';

            } catch (PDOException $ex) {
                error_log(
                    'Kesalahan edit jurnal: ' . $ex->getMessage()
                );

                $error = 'Terjadi kesalahan saat menyimpan jurnal. Silakan coba lagi.';
            }
        }
    }

    // Pertahankan isian jika validasi gagal
    $jurnal['tanggal'] = $tanggal;
    $jurnal['kegiatan'] = $kegiatan;
    $jurnal['hasil'] = $hasil;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Jurnal PKL | CariTempatPKL</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #26344b;
        }

        header {
            padding: 22px 6%;
            background: #173b70;
            color: white;
        }

        header h2 {
            margin: 0 0 7px;
        }

        header a {
            color: white;
        }

        main {
            max-width: 850px;
            margin: 30px auto;
            padding: 0 18px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 3px 12px #172b4d12;
        }

        .info {
            padding: 14px;
            margin-bottom: 18px;
            border-radius: 7px;
            background: #fff1ce;
            color: #765100;
        }

        .alert {
            padding: 13px;
            margin-bottom: 18px;
            border-radius: 7px;
            background: #ffe3e3;
            color: #a52222;
        }

        label {
            display: block;
            font-weight: bold;
            margin: 17px 0 7px;
        }

        input,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd5e2;
            border-radius: 7px;
            font: inherit;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        button,
        .btn {
            display: inline-block;
            margin-top: 18px;
            padding: 12px 17px;
            border: 0;
            border-radius: 7px;
            background: #205bb5;
            color: white;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-secondary {
            background: #64748b;
        }
    </style>
</head>

<body>

<header>
    <h2>Edit Jurnal PKL</h2>
    <a href="jurnal_siswa.php">← Kembali ke Jurnal Saya</a>
</header>

<main>
    <section class="card">
        <h3>Perbaiki Jurnal Kegiatan</h3>

        <p>
            <strong>Tempat PKL:</strong>
            <?= e($jurnal['nama_perusahaan']) ?><br>

            <strong>Lowongan:</strong>
            <?= e($jurnal['judul_lowongan']) ?>
        </p>

        <?php if (!empty($jurnal['catatan_verifikasi'])): ?>
            <div class="info">
                <strong>Catatan revisi dari guru/admin:</strong><br>
                <?= nl2br(e($jurnal['catatan_verifikasi'])) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="id_jurnal"
                value="<?= (int) $jurnal['id_jurnal'] ?>"
            >

            <label for="tanggal">Tanggal Kegiatan</label>
            <input
                type="date"
                name="tanggal"
                id="tanggal"
                max="<?= date('Y-m-d') ?>"
                value="<?= e($jurnal['tanggal']) ?>"
                required
            >

            <label for="kegiatan">Kegiatan yang Dilakukan</label>
            <textarea
                name="kegiatan"
                id="kegiatan"
                maxlength="10000"
                required
            ><?= e($jurnal['kegiatan']) ?></textarea>

            <label for="hasil">Hasil Kegiatan</label>
            <textarea
                name="hasil"
                id="hasil"
                maxlength="10000"
            ><?= e($jurnal['hasil'] ?? '') ?></textarea>

            <button
                type="submit"
                onclick="return confirm('Simpan perbaikan dan ajukan ulang jurnal?')"
            >
                Simpan &amp; Ajukan Ulang
            </button>

            <a href="jurnal_siswa.php" class="btn btn-secondary">
                Batal
            </a>
        </form>
    </section>
</main>

</body>
</html>
