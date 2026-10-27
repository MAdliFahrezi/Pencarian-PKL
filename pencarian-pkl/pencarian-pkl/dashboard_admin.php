
<?php
session_start();
require_once 'koneksi.php';

// Pastikan hanya admin yang bisa membuka dashboard
if (
    !isset($_SESSION['id_pengguna']) ||
    !isset($_SESSION['peran']) ||
    $_SESSION['peran'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

$namaAdmin = $_SESSION['nama_lengkap'] ?? 'Administrator';

// Statistik dashboard
$totalSiswa = (int) $pdo->query(
    "SELECT COUNT(*) FROM siswa"
)->fetchColumn();

$totalPerusahaan = (int) $pdo->query(
    "SELECT COUNT(*) FROM perusahaan"
)->fetchColumn();

$totalLowongan = (int) $pdo->query(
    "SELECT COUNT(*) FROM lowongan_pkl"
)->fetchColumn();

$totalPendaftaran = (int) $pdo->query(
    "SELECT COUNT(*) FROM pendaftaran"
)->fetchColumn();

$totalMenunggu = (int) $pdo->query(
    "SELECT COUNT(*) FROM pendaftaran
     WHERE status = 'menunggu'"
)->fetchColumn();

// Pendaftaran terbaru
$sql = "SELECT
            pg.tanggal_daftar,
            pg.status,
            u.nama_lengkap,
            l.judul_lowongan,
            pr.nama_perusahaan
        FROM pendaftaran pg
        JOIN siswa s ON pg.id_siswa = s.id_siswa
        JOIN pengguna u ON s.id_pengguna = u.id_pengguna
        JOIN lowongan_pkl l ON pg.id_lowongan = l.id_lowongan
        JOIN perusahaan pr ON l.id_perusahaan = pr.id_perusahaan
        ORDER BY pg.tanggal_daftar DESC
        LIMIT 8";

$pendaftaranTerbaru = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

function h($nilai) {
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

function labelStatus($status) {
    $labels = [
        'menunggu' => 'Menunggu',
        'diproses' => 'Diproses',
        'diterima' => 'Diterima',
        'ditolak' => 'Ditolak',
        'dibatalkan' => 'Dibatalkan'
    ];

    return $labels[$status] ?? ucfirst($status);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin | CariTempatPKL</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1e293b;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 245px;
            flex-shrink: 0;
            background: #123b75;
            color: white;
            padding: 28px 18px;
        }

        .brand {
            font-size: 21px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .brand span {
            color: #7dd3fc;
        }

        .subtitle {
            font-size: 12px;
            color: #cbdcf4;
            margin-bottom: 35px;
        }

        .menu-title {
            font-size: 11px;
            text-transform: uppercase;
            color: #a9c1e2;
            margin: 22px 12px 10px;
            letter-spacing: 1px;
        }

        .menu a {
            display: block;
            text-decoration: none;
            color: #e2ecfa;
            padding: 13px 14px;
            border-radius: 9px;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .menu a:hover,
        .menu a.active {
            background: #24549a;
            color: white;
        }

        .main {
            flex: 1;
            min-width: 0;
        }

        .topbar {
            background: white;
            padding: 22px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid #e5eaf1;
        }

        .topbar h1 {
            font-size: 23px;
        }

        .topbar p {
            font-size: 13px;
            color: #64748b;
            margin-top: 5px;
        }

        .admin-name {
            font-size: 13px;
            font-weight: bold;
            background: #eaf2ff;
            color: #17458a;
            padding: 11px 14px;
            border-radius: 10px;
        }

        .content {
            padding: 28px 30px;
        }

        .welcome {
            margin-bottom: 24px;
        }

        .welcome h2 {
            font-size: 22px;
            margin-bottom: 8px;
        }

        .welcome p {
            color: #64748b;
            font-size: 14px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            padding: 22px;
            border-radius: 14px;
            border: 1px solid #e7edf5;
            box-shadow: 0 3px 12px rgba(15, 40, 80, .04);
        }

        .stat-label {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 14px;
        }

        .stat-number {
            font-size: 30px;
            font-weight: bold;
            color: #153e79;
        }

        .stat-note {
            color: #64748b;
            font-size: 12px;
            margin-top: 9px;
        }

        .panel {
            background: white;
            border-radius: 14px;
            border: 1px solid #e7edf5;
            overflow: hidden;
        }

        .panel-head {
            padding: 21px 23px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .panel-head h3 {
            font-size: 17px;
        }

        .panel-head a {
            color: #1d5bbf;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
        }

        th, td {
            text-align: left;
            padding: 15px 20px;
            border-top: 1px solid #edf1f6;
            font-size: 13px;
        }

        th {
            color: #64748b;
            background: #f8fafc;
            font-weight: 600;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }

        .menunggu {
            color: #92400e;
            background: #fef3c7;
        }

        .diproses {
            color: #1d4ed8;
            background: #dbeafe;
        }

        .diterima {
            color: #166534;
            background: #dcfce7;
        }

        .ditolak, .dibatalkan {
            color: #991b1b;
            background: #fee2e2;
        }

        .empty {
            padding: 35px;
            text-align: center;
            color: #64748b;
        }

        .footer {
            padding: 24px 0;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
        }

        @media (max-width: 1050px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .layout {
                display: block;
            }

            .sidebar {
                width: 100%;
                padding: 20px;
            }

            .subtitle {
                margin-bottom: 15px;
            }

            .menu {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
            }

            .menu-title {
                display: none;
            }

            .menu a {
                padding: 10px;
                font-size: 12px;
            }

            .topbar {
                padding: 20px;
                align-items: flex-start;
                flex-direction: column;
            }

            .content {
                padding: 20px 14px;
            }

            .stats {
                gap: 10px;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-number {
                font-size: 25px;
            }
        }
    </style>
</head>

<body>
<div class="layout">

    <aside class="sidebar">
        <div class="brand">CariTempat<span>PKL</span></div>
        <div class="subtitle">Panel Administrasi</div>

        <div class="menu-title">Menu utama</div>
        <nav class="menu">
            <a href="dashboard_admin.php" class="active">▦ Dashboard</a>
            <a href="data_siswa.php">♙ Data Siswa</a>
            <a href="data_perusahaan.php">☷ Data Perusahaan</a>
            <a href="kelola_lowongan.php">⌕ Kelola Lowongan</a>
            <a href="monitoring_jurnal.php">Monitoring Jurnal PKL</a>
            <a href="admin_pendaftaran.php">▤ Pendaftaran</a>
            <a href="laporan_pkl.php">▤ Laporan PKL</a>
            <a href="cari_perusahaan.php">⌕ Lowongan PKL</a>
            <a href="status_pendaftaran.php">◷ Status Pendaftaran</a>
        </nav>

        <div class="menu-title">Akun</div>
        <nav class="menu">
            <a href="logout.php">⇥ Keluar</a>
        </nav>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <h1>Dashboard Admin</h1>
                <p>Ringkasan sistem pencarian tempat PKL</p>
            </div>

            <div class="admin-name">
                Admin: <?= h($namaAdmin) ?>
            </div>
        </header>

        <section class="content">
            <div class="welcome">
                <h2>Selamat datang, <?= h($namaAdmin) ?>! 👋</h2>
                <p>Berikut ringkasan data CariTempatPKL saat ini.</p>
            </div>

            <div class="stats">
                <div class="stat-card">
                    <div class="stat-label">Total Siswa</div>
                    <div class="stat-number"><?= $totalSiswa ?></div>
                    <div class="stat-note">Siswa terdaftar</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Total Perusahaan</div>
                    <div class="stat-number"><?= $totalPerusahaan ?></div>
                    <div class="stat-note">Data perusahaan</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Total Lowongan</div>
                    <div class="stat-number"><?= $totalLowongan ?></div>
                    <div class="stat-note">Semua lowongan PKL</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Total Pendaftaran</div>
                    <div class="stat-number"><?= $totalPendaftaran ?></div>
                    <div class="stat-note">
                        <?= $totalMenunggu ?> pendaftaran menunggu
                    </div>
                </div>
            </div>

            <section class="panel">
                <div class="panel-head">
                    <h3>Pendaftaran Terbaru</h3>
                    <a href="admin_pendaftaran.php">Kelola pendaftaran →</a>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Nama Siswa</th>
                                <th>Lowongan</th>
                                <th>Perusahaan</th>
                                <th>Tanggal Daftar</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (!$pendaftaranTerbaru): ?>
                            <tr>
                                <td colspan="6" class="empty">
                                    Belum ada pendaftaran siswa.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendaftaranTerbaru as $i => $item): ?>
                                <?php
                                $statusClass = in_array(
                                    $item['status'],
                                    ['menunggu', 'diproses', 'diterima', 'ditolak', 'dibatalkan'],
                                    true
                                ) ? $item['status'] : '';
                                ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= h($item['nama_lengkap']) ?></td>
                                    <td><?= h($item['judul_lowongan']) ?></td>
                                    <td><?= h($item['nama_perusahaan']) ?></td>
                                    <td><?= h($item['tanggal_daftar']) ?></td>
                                    <td>
                                        <span class="status <?= h($statusClass) ?>">
                                            <?= h(labelStatus($item['status'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="footer">
                © <?= date('Y') ?> CariTempatPKL — Panel Administrasi
            </div>
        </section>
    </main>

</div>
</body>
</html>
