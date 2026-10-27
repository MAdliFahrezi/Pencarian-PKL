
<?php
session_start();
require_once 'koneksi.php';

/* =========================
   1. PEMERIKSAAN AKSES
========================= */
if (
    !isset($_SESSION['id_pengguna'], $_SESSION['peran']) ||
    $_SESSION['peran'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

/* =========================
   2. FUNGSI BANTUAN
========================= */
function h($value): string
{
    if (!is_scalar($value) && $value !== null) {
        return '';
    }

    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function panjangTeks(string $teks): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($teks, 'UTF-8')
        : strlen($teks);
}

/* =========================
   3. TOKEN CSRF
========================= */
if (
    !isset($_SESSION['csrf_buat_akun_perusahaan']) ||
    !is_string($_SESSION['csrf_buat_akun_perusahaan']) ||
    $_SESSION['csrf_buat_akun_perusahaan'] === ''
) {
    $_SESSION['csrf_buat_akun_perusahaan'] = bin2hex(random_bytes(32));
}

$errors = [];
$idPerusahaan = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$nama = '';
$email = '';
$password = '';

/* =========================
   4. PROSES FORM
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !isset($_SESSION['csrf_buat_akun_perusahaan']) ||
        !is_string($_SESSION['csrf_buat_akun_perusahaan']) ||
        !hash_equals($_SESSION['csrf_buat_akun_perusahaan'], $token)
    ) {
        $errors[] = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $idPerusahaan = filter_input(
            INPUT_POST,
            'id_perusahaan',
            FILTER_VALIDATE_INT
        );

        // Pastikan input berupa string sebelum diproses.
        $namaInput = $_POST['nama_lengkap'] ?? '';
        $emailInput = $_POST['email'] ?? '';
        $passwordInput = $_POST['password'] ?? '';

        $nama = is_string($namaInput) ? trim($namaInput) : '';
        $email = is_string($emailInput)
            ? trim($emailInput)
            : '';
        $password = is_string($passwordInput)
            ? $passwordInput
            : '';

        /* Validasi ID perusahaan */
        if (
            $idPerusahaan === false ||
            $idPerusahaan === null ||
            $idPerusahaan < 1
        ) {
            $errors[] = 'Perusahaan tidak valid.';
        }

        /* Validasi nama */
        if (
            $nama === '' ||
            panjangTeks($nama) > 100
        ) {
            $errors[] = 'Nama lengkap wajib diisi, maksimal 100 karakter.';
        }

        /* Validasi email */
        if (
            $email === '' ||
            strlen($email) > 150 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $errors[] = 'Format email tidak valid atau terlalu panjang.';
        }

        /* Validasi password */
        if (
            !is_string($passwordInput) ||
            strlen($password) < 8 ||
            strlen($password) > 72
        ) {
            $errors[] = 'Password wajib berisi 8–72 byte.';
        }

        /* =========================
           5. SIMPAN KE DATABASE
        ========================= */
        if (!$errors) {
            try {
                $pdo->beginTransaction();

                /*
                 * Kunci baris perusahaan selama transaksi.
                 * Membantu mencegah dua akun dibuat untuk
                 * perusahaan yang sama secara bersamaan.
                 */
                $stmt = $pdo->prepare(
                    "SELECT id_perusahaan, nama_perusahaan, id_pengguna
                     FROM perusahaan
                     WHERE id_perusahaan = ?
                     FOR UPDATE"
                );

                $stmt->execute([$idPerusahaan]);

                $perusahaan = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$perusahaan) {
                    $errors[] = 'Data perusahaan tidak ditemukan.';
                } elseif (
                    $perusahaan['id_pengguna'] !== null &&
                    $perusahaan['id_pengguna'] !== ''
                ) {
                    $errors[] = 'Perusahaan ini sudah terhubung dengan akun login.';
                }

                /*
                 * Periksa apakah email sudah digunakan.
                 */
                if (!$errors) {
                    $stmt = $pdo->prepare(
                        "SELECT id_pengguna
                         FROM pengguna
                         WHERE email = ?
                         LIMIT 1"
                    );

                    $stmt->execute([$email]);

                    if ($stmt->fetchColumn() !== false) {
                        $errors[] = 'Email sudah digunakan. Gunakan email lain.';
                    }
                }

                if (!$errors) {
                    /*
                     * Hash password sebelum disimpan.
                     */
                    $passwordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    if ($passwordHash === false) {
                        throw new RuntimeException(
                            'Gagal membuat hash password.'
                        );
                    }

                    /*
                     * Buat akun login perusahaan.
                     */
                    $stmt = $pdo->prepare(
                        "INSERT INTO pengguna
                            (nama_lengkap, email, password_hash, peran)
                         VALUES (?, ?, ?, 'perusahaan')"
                    );

                    $stmt->execute([
                        $nama,
                        $email,
                        $passwordHash
                    ]);

                    $idPengguna = (int) $pdo->lastInsertId();

                    if ($idPengguna < 1) {
                        throw new RuntimeException(
                            'ID pengguna tidak valid.'
                        );
                    }

                    /*
                     * Hubungkan akun dengan perusahaan.
                     * Hanya memperbarui jika belum terhubung.
                     */
                    $stmt = $pdo->prepare(
                        "UPDATE perusahaan
                         SET id_pengguna = ?
                         WHERE id_perusahaan = ?
                           AND id_pengguna IS NULL"
                    );

                    $stmt->execute([
                        $idPengguna,
                        $idPerusahaan
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Gagal menghubungkan akun dengan perusahaan.'
                        );
                    }

                    /*
                     * Simpan kedua perubahan sekaligus.
                     */
                    $pdo->commit();

                    // Buat token baru setelah berhasil.
                    $_SESSION['csrf_buat_akun_perusahaan'] =
                        bin2hex(random_bytes(32));

                    // Kosongkan password dari variabel.
                    $password = '';

                    header('Location: data_perusahaan.php?akun=berhasil');
                    exit;
                }

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Detail error hanya masuk ke log server.
                error_log(
                    'Buat akun perusahaan gagal: ' .
                    $e->getMessage()
                );

                $errors[] =
                    'Akun gagal dibuat. Periksa data dan struktur database.';
            }
        }
    }
}

/* =========================
   6. AMBIL DATA PERUSAHAAN
========================= */
$perusahaan = null;

if (
    is_int($idPerusahaan) &&
    $idPerusahaan > 0
) {
    $stmt = $pdo->prepare(
        "SELECT id_perusahaan, nama_perusahaan, email, id_pengguna
         FROM perusahaan
         WHERE id_perusahaan = ?
         LIMIT 1"
    );

    $stmt->execute([$idPerusahaan]);

    $perusahaan = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$perusahaan && !$errors) {
    $errors[] =
        'Perusahaan tidak ditemukan. Kembali ke Data Perusahaan dan pilih perusahaan.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Buat Akun Perusahaan | CariTempatPKL</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 25px;
    font-family: Arial, sans-serif;
    background: #f4f7fb;
    color: #1e293b;
}

.card {
    max-width: 600px;
    margin: 25px auto;
    background: white;
    border: 1px solid #e3e9f3;
    border-radius: 15px;
    padding: 28px;
}

h1 {
    font-size: 24px;
    margin: 0 0 8px;
}

.muted {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
}

label {
    display: block;
    margin: 18px 0 7px;
    font-size: 14px;
    font-weight: bold;
}

input {
    width: 100%;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 14px;
}

button,
.btn {
    display: inline-block;
    border: 0;
    border-radius: 8px;
    padding: 12px 16px;
    background: #1d5bbf;
    color: white;
    text-decoration: none;
    font-weight: bold;
    cursor: pointer;
    font-size: 14px;
}

.actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 24px;
}

.btn.back {
    background: #e8eef7;
    color: #334155;
}

.error {
    background: #fef2f2;
    color: #991b1b;
    padding: 12px;
    border-radius: 8px;
    margin: 18px 0;
    font-size: 14px;
}

.info {
    background: #eff6ff;
    color: #1e40af;
    padding: 12px;
    border-radius: 8px;
    margin-top: 15px;
    font-size: 13px;
    line-height: 1.5;
}

@media (max-width: 480px) {
    body {
        padding: 12px;
    }

    .card {
        margin: 12px auto;
        padding: 20px;
    }
}
</style>
</head>

<body>
<main class="card">

<h1>Buat Akun Login Perusahaan</h1>

<p class="muted">
    Buat akun untuk perusahaan yang sudah terdaftar.
</p>

<?php if ($errors): ?>
<div class="error">
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= h($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($perusahaan && empty($perusahaan['id_pengguna'])): ?>

<div class="info">
    <strong>Perusahaan:</strong>
    <?= h($perusahaan['nama_perusahaan']) ?><br>

    <strong>Email perusahaan:</strong>
    <?= h($perusahaan['email'] ?: '-') ?>
</div>

<form method="POST" action="">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= h($_SESSION['csrf_buat_akun_perusahaan']) ?>"
    >

    <input
        type="hidden"
        name="id_perusahaan"
        value="<?= (int) $perusahaan['id_perusahaan'] ?>"
    >

    <label for="nama_lengkap">
        Nama lengkap penanggung jawab
    </label>

    <input
        id="nama_lengkap"
        name="nama_lengkap"
        type="text"
        maxlength="100"
        value="<?= h($nama) ?>"
        autocomplete="name"
        required
    >

    <label for="email">Email untuk login</label>

    <input
        id="email"
        name="email"
        type="email"
        maxlength="150"
        value="<?= h($email) ?>"
        autocomplete="email"
        required
    >

    <label for="password">Password awal</label>

    <input
        id="password"
        name="password"
        type="password"
        minlength="8"
        maxlength="72"
        autocomplete="new-password"
        required
    >

    <p class="muted">
        Gunakan password minimal 8 karakter.
        Berikan password awal kepada penanggung jawab perusahaan secara aman.
    </p>

    <div class="actions">
        <button type="submit">Buat Akun</button>

        <a class="btn back" href="data_perusahaan.php">
            Kembali
        </a>
    </div>
</form>

<?php elseif ($perusahaan && !empty($perusahaan['id_pengguna'])): ?>

<div class="info">
    Perusahaan ini sudah memiliki akun login.
</div>

<div class="actions">
    <a class="btn back" href="data_perusahaan.php">
        Kembali ke Data Perusahaan
    </a>
</div>

<?php else: ?>

<div class="actions">
    <a class="btn back" href="data_perusahaan.php">
        Kembali ke Data Perusahaan
    </a>
</div>

<?php endif; ?>

</main>
</body>
</html>
