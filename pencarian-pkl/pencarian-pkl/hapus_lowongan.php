```php
<?php
session_start();
require_once 'koneksi.php';

// Hanya admin yang boleh menghapus lowongan.
if (
    !isset($_SESSION['id_pengguna'], $_SESSION['peran']) ||
    $_SESSION['peran'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

// Penghapusan hanya boleh melalui POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: kelola_lowongan.php');
    exit;
}

// Validasi token CSRF.
$token = $_POST['csrf_token'] ?? '';
$tokenSesi = $_SESSION['csrf_kelola_lowongan'] ?? '';

if (
    !is_string($token) ||
    !is_string($tokenSesi) ||
    $tokenSesi === '' ||
    !hash_equals($tokenSesi, $token)
) {
    http_response_code(403);
    exit('Permintaan ditolak. Token keamanan tidak valid. Muat ulang halaman.');
}

// Validasi ID lowongan.
$idInput = $_POST['id_lowongan'] ?? '';

if (!is_string($idInput)) {
    header('Location: kelola_lowongan.php?pesan=id_tidak_valid');
    exit;
}

$id = filter_var($idInput, FILTER_VALIDATE_INT);

if ($id === false || $id < 1) {
    header('Location: kelola_lowongan.php?pesan=id_tidak_valid');
    exit;
}

try {
    // Mulai transaksi agar pemeriksaan dan penghapusan konsisten.
    $pdo->beginTransaction();

    // Kunci data lowongan selama proses berlangsung.
    $cek = $pdo->prepare(
        "SELECT id_lowongan
         FROM lowongan_pkl
         WHERE id_lowongan = ?
         FOR UPDATE"
    );
    $cek->execute([$id]);

    if (!$cek->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        header('Location: kelola_lowongan.php?pesan=lowongan_tidak_ditemukan');
        exit;
    }

    // Periksa apakah lowongan memiliki pendaftaran.
    $cekPendaftaran = $pdo->prepare(
        "SELECT COUNT(*)
         FROM pendaftaran
         WHERE id_lowongan = ?"
    );
    $cekPendaftaran->execute([$id]);

    if ((int) $cekPendaftaran->fetchColumn() > 0) {
        $pdo->rollBack();
        header('Location: kelola_lowongan.php?pesan=lowongan_memiliki_pendaftaran');
        exit;
    }

    // Hapus lowongan yang tidak memiliki pendaftaran.
    $hapus = $pdo->prepare(
        "DELETE FROM lowongan_pkl
         WHERE id_lowongan = ?"
    );
    $hapus->execute([$id]);

    if ($hapus->rowCount() !== 1) {
        throw new RuntimeException('Lowongan tidak berhasil dihapus.');
    }

    $pdo->commit();

    // Perbarui token setelah penghapusan berhasil.
    $_SESSION['csrf_kelola_lowongan'] = bin2hex(random_bytes(32));

    header('Location: kelola_lowongan.php?pesan=hapus_lowongan_berhasil');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Gagal menghapus lowongan: ' . $e->getMessage());

    header('Location: kelola_lowongan.php?pesan=gagal_hapus_lowongan');
    exit;
}