
<?php
require_once 'koneksi.php';

$jurusanList = ['RPL', 'BR', 'TKJ', 'AKL', 'ULW', 'MPLB'];
$passwordAwal = 'Siswa123!';

try {
    $pdo->beginTransaction();

    $cekJurusan = $pdo->prepare(
        "SELECT id_jurusan FROM jurusan WHERE kode_jurusan = ?"
    );

    $buatPengguna = $pdo->prepare(
        "INSERT INTO pengguna
        (nama_lengkap, email, password_hash, peran)
        VALUES (?, ?, ?, 'siswa')"
    );

    $buatSiswa = $pdo->prepare(
        "INSERT INTO siswa
        (id_pengguna, id_jurusan, nis, kelas)
        VALUES (?, ?, ?, ?)"
    );

    $jumlah = 0;

    foreach ($jurusanList as $kode) {
        $cekJurusan->execute([$kode]);
        $idJurusan = $cekJurusan->fetchColumn();

        if (!$idJurusan) {
            throw new Exception(
                "Jurusan $kode belum ada di database."
            );
        }

        for ($i = 1; $i <= 7; $i++) {
            $nomor = str_pad($i, 2, '0', STR_PAD_LEFT);
            $nis = $kode . '2026' . $nomor;
            $email = strtolower($kode) . $nomor
                   . '@siswa.test';
            $nama = 'Siswa ' . $kode . ' ' . $nomor;

            // Lewati jika email atau NIS sudah ada.
            $cek = $pdo->prepare(
                "SELECT 1 FROM pengguna WHERE email = ?
                 UNION
                 SELECT 1 FROM siswa WHERE nis = ?
                 LIMIT 1"
            );
            $cek->execute([$email, $nis]);

            if ($cek->fetchColumn()) {
                continue;
            }

            $hash = password_hash(
                $passwordAwal,
                PASSWORD_DEFAULT
            );

            $buatPengguna->execute([
                $nama, $email, $hash
            ]);

            $idPengguna = $pdo->lastInsertId();

            $buatSiswa->execute([
                $idPengguna,
                $idJurusan,
                $nis,
                'XII'
            ]);

            $jumlah++;
        }
    }

    $pdo->commit();

    echo "Berhasil menambahkan $jumlah akun siswa.";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    exit("Gagal: " . htmlspecialchars($e->getMessage()));
}
