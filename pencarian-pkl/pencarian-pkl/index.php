<?php
declare(strict_types=1);

session_start();

// Jika pengguna sudah login, arahkan ke halaman sesuai perannya.
if (isset($_SESSION['id_pengguna'], $_SESSION['peran'])) {
    $dashboard = [
        'siswa'       => 'cari_perusahaan.php',
        'admin'       => 'dashboard_admin.php',
        'guru'        => 'dashboard_guru.php',
        'koordinator' => 'dashboard_koordinator.php',
        'perusahaan'  => 'dashboard_perusahaan.php',
    ];

    $peran = $_SESSION['peran'];

    if (isset($dashboard[$peran])) {
        header('Location: ' . $dashboard[$peran]);
        exit;
    }

    // Jika peran tidak dikenali, kembali ke login.
    session_unset();
    session_destroy();
}

// Jika belum login, tampilkan halaman login.
header('Location: login.php');
exit;
