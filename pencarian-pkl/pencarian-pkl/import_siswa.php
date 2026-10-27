```php
<?php
session_start();

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (
    !isset($_SERVER['REMOTE_ADDR']) ||
    !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)
) {
    http_response_code(403);
    exit('File impor hanya boleh dijalankan dari komputer lokal.');
}

$excelPath = __DIR__ . '/data_siswa_XI_acak_10_per_lokal.xlsx';

if (!is_file($excelPath)) {
    exit('File Excel tidak ditemukan: ' .
        htmlspecialchars(basename($excelPath), ENT_QUOTES, 'UTF-8'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Impor Siswa CariTempatPKL</title>
    </head>
    <body>
        <h2>Impor Siswa CariTempatPKL</h2>
        <p>Pastikan database sudah di-backup sebelum melanjutkan.</p>
        <p>File sumber: data_siswa_XI_acak_10_per_lokal.xlsx</p>

        <form method="post"
              onsubmit="return confirm('Yakin ingin mengimpor siswa dari Excel?');">
            <button type="submit">Mulai Impor Siswa</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

$created = [];
$skipped = [];
$handle = null;

try {
    // Baca file Excel.
    $spreadsheet = IOFactory::load($excelPath);
    $sheet = $spreadsheet->getSheetByName('Sheet1')
        ?? $spreadsheet->getActiveSheet();

    $rows = $sheet->toArray(null, true, true, false);

    if (count($rows) < 2) {
        throw new RuntimeException('File Excel tidak memiliki data siswa.');
    }

    // Normalisasi header.

$header = array_map(
    function ($value) {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);
        $value = strtolower(trim($value));

        // Ubah spasi menjadi garis bawah.
        $value = preg_replace('/\s+/', '_', $value);

        return $value;
    },
    $rows[0]
);


    $expected = [
        'nisn',
        'nama_lengkap',
        'jenis_kelamin',
        'kelas_asal',
        'jurusan'
    ];

    if ($header !== $expected) {
        throw new RuntimeException(
            'Format kolom Excel tidak sesuai. Kolom terbaca: ' .
            implode(' | ', $header) .
            '. Kolom yang diharapkan: ' .
            implode(' | ', $expected)
        );
    }

    $jurusanMap = [
        'RPL'  => 1,
        'PMS'  => 2,
        'TKJ'  => 3,
        'AKL'  => 4,
        'ULW'  => 5,
        'MPLB' => 6
    ];

    $pdo->beginTransaction();

    $cekNis = $pdo->prepare(
        'SELECT id_siswa FROM siswa WHERE nis = ? LIMIT 1'
    );

    $cekEmail = $pdo->prepare(
        'SELECT id_pengguna FROM pengguna WHERE email = ? LIMIT 1'
    );

    $tambahPengguna = $pdo->prepare(
        "INSERT INTO pengguna
            (nama_lengkap, email, password_hash, peran)
         VALUES (?, ?, ?, 'siswa')"
    );

    $tambahSiswa = $pdo->prepare(
        'INSERT INTO siswa
            (id_pengguna, id_jurusan, nis, kelas, jenis_kelamin)
         VALUES (?, ?, ?, ?, ?)'
    );

    // Mulai dari baris kedua karena baris pertama adalah header.
    foreach (array_slice($rows, 1) as $index => $row) {
        $nomorBaris = $index + 2;

        if (count($row) < 5) {
            $skipped[] = "Baris {$nomorBaris}: jumlah kolom tidak cukup.";
            continue;
        }

        $nisn = trim((string) ($row[0] ?? ''));
        $nama = trim((string) ($row[1] ?? ''));
        $gender = trim((string) ($row[2] ?? ''));
        $kelasAsal = trim((string) ($row[3] ?? ''));
        $jurusan = strtoupper(trim((string) ($row[4] ?? '')));

        // Lewati baris kosong.
        if (
            $nisn === '' &&
            $nama === '' &&
            $gender === '' &&
            $kelasAsal === '' &&
            $jurusan === ''
        ) {
            continue;
        }

        if (
            $nisn === '' ||
            $nama === '' ||
            !isset($jurusanMap[$jurusan]) ||
            !in_array($gender, ['Laki-laki', 'Perempuan'], true)
        ) {
            throw new RuntimeException(
                "Data tidak valid pada baris {$nomorBaris}: {$nama}. " .
                'Periksa NISN, jenis kelamin, dan jurusan.'
            );
        }

        // Cegah NISN ganda di database.
        $cekNis->execute([$nisn]);

        if ($cekNis->fetch()) {
            $skipped[] = $nama . ' - NISN sudah terdaftar';
            continue;
        }

        // Email dibuat berdasarkan NISN.
        $basisEmail = preg_replace('/[^a-zA-Z0-9]/', '', $nisn);

        if ($basisEmail === '') {
            throw new RuntimeException(
                "NISN tidak valid untuk membuat email pada baris {$nomorBaris}."
            );
        }

        $email = 'siswa' . strtolower($basisEmail) . '@siswa.test';

        $cekEmail->execute([$email]);

        if ($cekEmail->fetch()) {
            $skipped[] = $nama . ' - email sudah terdaftar';
            continue;
        }

        // Password awal acak dan disimpan dalam bentuk hash.
        $password = bin2hex(random_bytes(8));
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $tambahPengguna->execute([
            $nama,
            $email,
            $passwordHash
        ]);

        $idPengguna = (int) $pdo->lastInsertId();

        // Data sumber berasal dari kelas XI.
        // Sesuaikan nilai ini jika kelas siswa di database harus tetap XI.
        $kelasDatabase = 'XII';

        $tambahSiswa->execute([
            $idPengguna,
            $jurusanMap[$jurusan],
            $nisn,
            $kelasDatabase,
            $gender
        ]);

        $created[] = [
            'nama' => $nama,
            'nisn' => $nisn,
            'email' => $email,
            'password' => $password,
            'kelas' => $kelasDatabase,
            'jurusan' => $jurusan
        ];
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    exit(
        'Impor gagal. Perubahan database telah dibatalkan jika transaksi ' .
        'masih berlangsung.<br>Detail: ' .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
    );
} finally {
    if (isset($spreadsheet)) {
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Hasil Impor Siswa</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 25px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
        }

        th {
            background: #eaf2f8;
        }

        .peringatan {
            padding: 12px;
            background: #fff4d6;
            margin: 15px 0;
        }

        button {
            padding: 10px 15px;
            cursor: pointer;
        }
    </style>
</head>
<body>

<h2>Hasil Impor Siswa CariTempatPKL</h2>

<p><strong>Akun berhasil dibuat:</strong> <?= count($created) ?></p>
<p><strong>Data dilewati:</strong> <?= count($skipped) ?></p>

<div class="peringatan">
    Simpan daftar akun dengan aman. Password awal hanya ditampilkan
    pada hasil impor ini.
</div>

<button onclick="unduhAkun()">Unduh Daftar Akun CSV</button>

<table id="tabelAkun">
    <thead>
        <tr>
            <th>Nama</th>
            <th>NISN</th>
            <th>Email</th>
            <th>Password Awal</th>
            <th>Kelas</th>
            <th>Jurusan</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($created as $akun): ?>
        <tr>
            <td><?= htmlspecialchars($akun['nama'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($akun['nisn'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($akun['email'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($akun['password'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($akun['kelas'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($akun['jurusan'], ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($skipped): ?>
    <h3>Data yang dilewati</h3>
    <ul>
        <?php foreach ($skipped as $item): ?>
            <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<script>
function unduhAkun() {
    const tabel = document.getElementById('tabelAkun');
    const baris = [...tabel.querySelectorAll('tr')];

    const csv = baris.map(tr =>
        [...tr.querySelectorAll('th, td')]
            .map(sel => '"' + sel.innerText.replace(/"/g, '""') + '"')
            .join(',')
    ).join('\r\n');

    const blob = new Blob(
        ['\ufeff' + csv],
        {type: 'text/csv;charset=utf-8;'}
    );

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = 'akun_siswa_baru.csv';
    link.click();

    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
</script>

</body>
</html>
