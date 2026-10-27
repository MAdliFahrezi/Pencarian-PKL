
<?php
session_start();
require_once 'koneksi.php';

// 1. Hak akses: hanya admin.
if (
    !isset($_SESSION['id_pengguna']) ||
    !isset($_SESSION['peran']) ||
    $_SESSION['peran'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

// 2. Mengamankan output HTML.
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Memastikan input berupa teks.
function inputTeks($key)
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

// Menghitung panjang karakter Unicode.
function panjangTeks($value)
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

// 3. Membuat token CSRF yang disimpan di sesi.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$nama = '';
$bidang = '';
$alamat = '';
$kota = '';
$telepon = '';
$email = '';
$deskripsi = '';

// 4. Memproses formulir.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Periksa token sebelum menerima data.
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

    // 5. Validasi input berdasarkan struktur tabel perusahaan.
    if ($nama === '') {
        $error = 'Nama perusahaan wajib diisi.';

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
            $sql = "INSERT INTO perusahaan
                    (nama_perusahaan, bidang_usaha, alamat, kota,
                     telepon, email, deskripsi)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $nama,
                $bidang,
                $alamat,
                $kota,
                $telepon,
                $email,
                $deskripsi
            ]);

            // Ganti token setelah operasi berhasil.
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header(
                'Location: data_perusahaan.php?pesan=tambah_berhasil'
            );
            exit;

        } catch (PDOException $e) {
            error_log(
                'Gagal menambahkan perusahaan: ' . $e->getMessage()
            );

            $error = 'Data perusahaan gagal disimpan. Periksa kembali data perusahaan.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Perusahaan | CariTempatPKL</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 15px;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1e293b;
        }

        .container {
            max-width: 760px;
            margin: auto;
        }

        .header {
            background: #123b75;
            color: white;
            padding: 24px;
            border-radius: 12px 12px 0 0;
        }

        .header h1 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        .header p {
            margin: 0;
            color: #dbeafe;
            font-size: 14px;
        }

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

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 13px;
            font-weight: bold;
        }

        input, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d7e0ec;
            border-radius: 8px;
            font: inherit;
            font-size: 14px;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        input:focus, textarea:focus {
            outline: 2px solid #bfdbfe;
            border-color: #2563eb;
        }

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

        .btn:hover {
            background: #17458a;
        }

        .btn-cancel {
            background: #e8eef7;
            color: #334155;
        }

        .btn-cancel:hover {
            background: #d8e2f0;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .required {
            color: #dc2626;
        }

        @media (max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <header class="header">
        <h1>Tambah Perusahaan</h1>
        <p>Masukkan informasi perusahaan mitra PKL.</p>
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
                    <label for="nama_perusahaan">
                        Nama Perusahaan <span class="required">*</span>
                    </label>
                    <input
                        id="nama_perusahaan"
                        name="nama_perusahaan"
                        value="<?= h($nama) ?>"
                        maxlength="150"
                        required
                    >
                </div>

                <div class="field">
                    <label for="bidang_usaha">Bidang Usaha</label>
                    <input
                        id="bidang_usaha"
                        name="bidang_usaha"
                        value="<?= h($bidang) ?>"
                        maxlength="100"
                    >
                </div>

                <div class="field">
                    <label for="kota">Kota</label>
                    <input
                        id="kota"
                        name="kota"
                        value="<?= h($kota) ?>"
                        maxlength="100"
                    >
                </div>

                <div class="field">
                    <label for="telepon">Telepon</label>
                    <input
                        id="telepon"
                        name="telepon"
                        value="<?= h($telepon) ?>"
                        maxlength="30"
                    >
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="<?= h($email) ?>"
                        maxlength="100"
                    >
                </div>

                <div class="field full">
                    <label for="alamat">Alamat</label>
                    <textarea id="alamat" name="alamat"><?= h($alamat) ?></textarea>
                </div>

                <div class="field full">
                    <label for="deskripsi">Deskripsi Perusahaan</label>
                    <textarea id="deskripsi" name="deskripsi"><?= h($deskripsi) ?></textarea>
                </div>
            </div>

            <div class="actions">
                <button class="btn" type="submit">Simpan Perusahaan</button>
                <a class="btn btn-cancel" href="data_perusahaan.php">
                    Batal
                </a>
            </div>
        </form>
    </main>
</div>
</body>
</html>
