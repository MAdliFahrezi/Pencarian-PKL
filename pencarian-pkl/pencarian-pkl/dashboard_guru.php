
<?php
session_start();
require_once 'koneksi.php';

/* =====================================
   1. PEMERIKSAAN AKSES GURU
===================================== */
if (
    !isset($_SESSION['id_pengguna']) ||
    !isset($_SESSION['peran']) ||
    $_SESSION['peran'] !== 'guru'
) {
    header('Location: login.php');
    exit;
}

/* =====================================
   2. FUNGSI KEAMANAN OUTPUT
===================================== */
function e($nilai): string
{
    return htmlspecialchars(
        (string) $nilai,
        ENT_QUOTES,
        'UTF-8'
    );
}

/* =====================================
   3. TOKEN CSRF UNTUK LOGOUT
===================================== */
if (
    empty($_SESSION['csrf_logout']) ||
    !is_string($_SESSION['csrf_logout'])
) {
    $_SESSION['csrf_logout'] = bin2hex(random_bytes(32));
}

$csrfLogout = $_SESSION['csrf_logout'];

/* =====================================
   4. AMBIL STATISTIK JURNAL
===================================== */
$stat = [
    'total' => 0,
    'menunggu' => 0,
    'disetujui' => 0,
    'revisi' => 0
];

$errorStatistik = false;

try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(
                SUM(status_verifikasi = 'menunggu'),
                0
            ) AS menunggu,
            COALESCE(
                SUM(status_verifikasi = 'disetujui'),
                0
            ) AS disetujui,
            COALESCE(
                SUM(status_verifikasi = 'perlu_revisi'),
                0
            ) AS revisi
        FROM jurnal_pkl
    ");

    $hasil = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($hasil !== false) {
        $stat = $hasil;
    }
} catch (PDOException $e) {
    error_log(
        '[dashboard_guru.php] Gagal mengambil statistik jurnal: '
        . $e->getMessage()
    );

    $errorStatistik = true;
}

/* =====================================
   5. NAMA GURU
===================================== */
$namaGuru = $_SESSION['nama_lengkap'] ?? 'Guru';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Guru | CariTempatPKL</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #26344b;
        }
        header {
            padding: 25px 6%;
            background: #173b70;
            color: white;
        }
        header h2 { margin: 0 0 8px; }
        main {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 18px;
        }
        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }
        .card {
            padding: 22px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 3px 12px #172b4d10;
            margin-bottom: 20px;
        }
        .number {
            font-size: 32px;
            font-weight: bold;
            margin-top: 10px;
        }
        .muted { color: #718096; }
        .menu {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .link {
            display: block;
            padding: 20px;
            border: 1px solid #e1e8f2;
            border-radius: 10px;
            color: #205bb5;
            text-decoration: none;
            font-weight: bold;
        }
        .link:hover { background: #f4f8ff; }
        .logout { color: white; }
        @media (max-width: 750px) {
            .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .menu { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<header>
    <h2>Dashboard Guru</h2>
    <p>Selamat datang, <?= e($namaGuru) ?>!</p>
    <form action="logout.php" method="POST" style="display:inline;">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= e($csrfLogout) ?>"
    >
    <button
        type="submit"
        class="logout"
        style="background:none;border:none;cursor:pointer;font-size:14px;text-decoration:underline;"
    >
        Keluar
    </button>
</form>
</header>

<main>
    <?php if ($errorStatistik): ?>
        <section class="card">
            <p>Statistik jurnal belum dapat dimuat. Silakan coba kembali nanti.</p>
        </section>
    <?php endif; ?>

    <section class="cards">
        <div class="card">
            <div class="muted">Total Jurnal</div>
            <div class="number"><?= (int) $stat['total'] ?></div>
        </div>
        <div class="card">
            <div class="muted">Menunggu</div>
            <div class="number"><?= (int) $stat['menunggu'] ?></div>
        </div>
        <div class="card">
            <div class="muted">Disetujui</div>
            <div class="number"><?= (int) $stat['disetujui'] ?></div>
        </div>
        <div class="card">
            <div class="muted">Perlu Revisi</div>
            <div class="number"><?= (int) $stat['revisi'] ?></div>
        </div>
    </section>

    <section class="card">
        <h3>Menu Guru</h3>
        <div class="menu">
            <a class="link" href="monitoring_jurnal.php">
                Monitoring dan Verifikasi Jurnal →
            </a>
            <form action="logout.php" method="POST">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= e($csrfLogout) ?>"
    >
    <button
        type="submit"
        class="link"
        style="width:100%;background:white;cursor:pointer;text-align:left;font-family:Arial,sans-serif;font-size:14px;"
    >
        Keluar dari Sistem →
    </button>
</form>
        </div>
    </section>
</main>
</body>
</html>
