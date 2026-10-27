
<?php
session_start();
require_once 'koneksi.php';

// Pastikan hanya admin yang dapat menghapus perusahaan.
if (
    !isset($_SESSION['id_pengguna']) ||
    !isset($_SESSION['peran']) ||
    $_SESSION['peran'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

// Penghapusan hanya boleh melalui metode POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: data_perusahaan.php');
    exit;
}

// Validasi token CSRF.
$token = $_POST['csrf_token'] ?? '';

if (
    !is_string($token) ||
    empty($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    http_response_code(403);
    exit('Permintaan ditolak. Token keamanan tidak valid. Silakan muat ulang halaman.');
}

// Validasi ID perusahaan.
$id = filter_input(INPUT_POST, 'id_perusahaan', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: data_perusahaan.php?pesan=id_tidak_valid');
    exit;
}

try {
    // Periksa apakah perusahaan masih mempunyai lowongan.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM lowongan_pkl WHERE id_perusahaan = ?"
    );
    $stmt->execute([$id]);

    $totalLowongan = (int) $stmt->fetchColumn();

    if ($totalLowongan > 0) {
        header('Location: data_perusahaan.php?pesan=masih_memiliki_lowongan');
        exit;
    }

    // Hapus perusahaan hanya jika tidak mempunyai lowongan.
    $stmt = $pdo->prepare(
        "DELETE FROM perusahaan WHERE id_perusahaan = ?"
    );
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        // Perbarui token setelah penghapusan berhasil.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        header('Location: data_perusahaan.php?pesan=hapus_berhasil');
    } else {
        header('Location: data_perusahaan.php?pesan=data_tidak_ditemukan');
    }

    exit;

} catch (PDOException $e) {
    // Catat detail kesalahan di log server, bukan di halaman pengguna.
    error_log('Gagal menghapus perusahaan: ' . $e->getMessage());

    header('Location: data_perusahaan.php?pesan=gagal_hapus');
    exit;
}
