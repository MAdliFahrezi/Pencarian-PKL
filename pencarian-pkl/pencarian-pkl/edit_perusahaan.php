
<?php
session_start();
require_once 'koneksi.php';

// 1. Batasi akses hanya untuk admin.
if (
    !isset($_SESSION['id_pengguna']) ||
    !isset($_SESSION['peran']) ||
    $_SESSION['peran'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

// 2. Fungsi untuk mengamankan output HTML.
function h($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Mengambil input teks dengan aman.
function inputTeks($key)
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

// Menghitung panjang karakter, termasuk karakter Unicode.
function panjangTeks($value)
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

// 3. Buat token CSRF jika belum tersedia.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 4. Validasi ID perusahaan.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('ID perusahaan tidak valid.');
}

// 5. Ambil data perusahaan.
$stmt = $pdo->prepare(
    "SELECT * FROM perusahaan WHERE id_perusahaan = ?"
);
$stmt->execute([$id]);
$perusahaan = $stmt->fetch();

if (!$perusahaan) {
    http_response_code(404);
    exit('Data perusahaan tidak ditemukan.');
}

$error = '';

// 6. Proses formulir.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Periksa token CSRF sebelum memproses perubahan.
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan muat ulang halaman.');
    }

    $nama = inputTeks('nama_perusahaan');
    $bidang = inputTeks('bidang_usaha');
    $alamat = inputTeks('alamat');
    $kota = inputTeks('kota');
    $telepon = inputTeks('telepon');
    $email = inputTeks('email');
    $deskripsi = inputTeks('deskripsi');

    // Pertahankan nilai input agar tidak hilang jika validasi gagal.
    $perusahaan['nama_perusahaan'] = $nama;
    $perusahaan['bidang_usaha'] = $bidang;
    $perusahaan['alamat'] = $alamat;
    $perusahaan['kota'] = $kota;
    $perusahaan['telepon'] = $telepon;
    $perusahaan['email'] = $email;
    $perusahaan['deskripsi'] = $deskripsi;

    // Validasi wajib isi.
    if ($nama === '') {
        $error = 'Nama perusahaan wajib diisi.';

    // Validasi panjang sesuai struktur database.
    } elseif (panjangTeks($nama) > 150) {
        $error = 'Nama perusahaan maksimal 150 karakter.';

    } elseif (panjangTeks($bidang) > 100) {
        $error = 'Bidang usaha maksimal 100 karakter.';

    } elseif (panjangTeks($kota) > 100) {
        $error = 'Kota maksimal 100 karakter.';

    } elseif (panjangTeks($telepon) > 30) {
        $error = 'Nomor telepon maksimal 30 karakter.';

    } elseif (panjangTeks($email) > 100) {
        $error = 'Email maksimal 100 karakter.';

    } elseif (
        $email !== '' &&
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $error = 'Format email tidak valid.';

    } else {
        try {
            $sql = "UPDATE perusahaan SET
                        nama_perusahaan = ?,
                        bidang_usaha = ?,
                        alamat = ?,
                        kota = ?,
                        telepon = ?,
                        email = ?,
                        deskripsi = ?
                    WHERE id_perusahaan = ?";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $nama,
                $bidang,
                $alamat,
                $kota,
                $telepon,
                $email,
                $deskripsi,
                $id
            ]);

            // Buat token baru setelah berhasil menyimpan.
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header(
                'Location: data_perusahaan.php?pesan=edit_berhasil'
            );
            exit;

        } catch (PDOException $e) {
            // Detail error database tidak ditampilkan kepada pengguna.
            error_log('Gagal memperbarui perusahaan: ' . $e->getMessage());

            $error = 'Data gagal diperbarui. Periksa kembali data perusahaan.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Perusahaan | CariTempatPKL</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 30px 15px;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1e293b;
        }

        .container { max-width: 760px; margin: auto; }

        .header {
            background: #123b75;
            color: white;
            padding: 24px;
            border-radius: 12px 12px 0 0;
        }

        .header h1 { margin: 0 0 8px; font-size: 24px; }
        .header p { margin: 0; color: #dbeafe; font-size: 14px; }

        .form-card {
            background: white;
            padding: 25px;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,.05);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .field { display: flex; flex-direction: column; gap: 7px; }
        .full { grid-column: 1 / -1; }

        label { font-size: 13px; font-weight: bold; }

        input, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d7e0ec;
            border-radius: 8px;
            font: inherit;
            font-size: 14px;
        }

        textarea { min-height: 110px; resize: vertical; }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 24px;
        }

        .btn {
            display: inline-block;
            padding: 12px 17px;
            border: none;
            border-radius: 8px;
            background: #1d5bbf;
            color: white;
            font-weight: bold;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }

        .btn:hover { background: #17458a; }

        .btn-cancel { background: #e8eef7; color: #334155; }
        .btn-cancel:hover { background: #d8e2f0; }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .full { grid-column: auto; }
        }
    </style>
</head>
<body>
<div class="container">
    <header class="header">
        <h1>Edit Perusahaan</h1>
        <p>Perbarui informasi perusahaan mitra PKL.</p>
    </header>

    <main class="form-card">
        <?php if ($error !== ''): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['csrf_token']) ?>"
            >

            <div class="form-grid">
                <div class="field full">
                    <label for="nama_perusahaan">Nama Perusahaan *</label>
                    <input
                        id="nama_perusahaan"
                        name="nama_perusahaan"
                        maxlength="150"
                        value="<?= h($perusahaan['nama_perusahaan']) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="bidang_usaha">Bidang Usaha</label>
                    <input
                        id="bidang_usaha"
                        name="bidang_usaha"
                        maxlength="100"
                        value="<?= h($perusahaan['bidang_usaha']) ?>"
                    >
                </div>

                <div class="field">
                    <label for="kota">Kota</label>
                    <input
                        id="kota"
                        name="kota"
                        maxlength="100"
                        value="<?= h($perusahaan['kota']) ?>"
                    >
                </div>

                <div class="field">
                    <label for="telepon">Telepon</label>
                    <input
                        id="telepon"
                        name="telepon"
                        maxlength="30"
                        value="<?= h($perusahaan['telepon']) ?>"
                    >
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        maxlength="100"
                        value="<?= h($perusahaan['email']) ?>"
                    >
                </div>

                <div class="field full">
                    <label for="alamat">Alamat</label>
                    <textarea
                        id="alamat"
                        name="alamat"
                    ><?= h($perusahaan['alamat']) ?></textarea>
                </div>

                <div class="field full">
                    <label for="deskripsi">Deskripsi Perusahaan</label>
                    <textarea
                        id="deskripsi"
                        name="deskripsi"
                    ><?= h($perusahaan['deskripsi']) ?></textarea>
                </div>
            </div>

            <div class="actions">
                <button class="btn" type="submit">Simpan Perubahan</button>
                <a class="btn btn-cancel" href="data_perusahaan.php">Batal</a>
            </div>
        </form>
    </main>
</div>
</body>
</html>
