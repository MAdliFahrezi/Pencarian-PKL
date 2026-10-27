
<?php
session_start();
require_once 'koneksi.php';

/* ===============================
   1. CEK AKSES ADMIN
================================ */
if (
    !isset($_SESSION['id_pengguna']) ||
    !isset($_SESSION['peran']) ||
    $_SESSION['peran'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

/* ===============================
   2. FUNGSI BANTU
================================ */
function h($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function tanggalValid(string $tanggal): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $tanggal);

    return $date !== false
        && $date->format('Y-m-d') === $tanggal;
}

/* ===============================
   3. VALIDASI ID LOWONGAN
================================ */
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: kelola_lowongan.php?pesan=id_tidak_valid');
    exit;
}

$error = '';
$lowongan = null;
$perusahaanList = [];
$jurusanList = [];

/* ===============================
   4. TOKEN CSRF
================================ */
if (
    empty($_SESSION['csrf_edit_lowongan']) ||
    !is_string($_SESSION['csrf_edit_lowongan'])
) {
    $_SESSION['csrf_edit_lowongan'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_edit_lowongan'];

/* ===============================
   5. AMBIL DATA LOWONGAN
================================ */
try {
    $stmt = $pdo->prepare(
        "SELECT *
         FROM lowongan_pkl
         WHERE id_lowongan = ?"
    );

    $stmt->execute([$id]);
    $lowongan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lowongan) {
        header('Location: kelola_lowongan.php?pesan=lowongan_tidak_ditemukan');
        exit;
    }

    $perusahaanList = $pdo->query(
        "SELECT id_perusahaan, nama_perusahaan
         FROM perusahaan
         ORDER BY nama_perusahaan"
    )->fetchAll(PDO::FETCH_ASSOC);

    $jurusanList = $pdo->query(
        "SELECT id_jurusan, kode_jurusan, nama_jurusan
         FROM jurusan
         ORDER BY nama_jurusan"
    )->fetchAll(PDO::FETCH_ASSOC);

    /* ===============================
       6. PROSES FORM EDIT
    ================================ */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        /* Validasi CSRF */
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($csrf, $token)
        ) {
            $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
        } else {

            /* Validasi tipe input */
            $perusahaanRaw = $_POST['id_perusahaan'] ?? '';
            $jurusanRaw = $_POST['id_jurusan'] ?? '';
            $judulRaw = $_POST['judul_lowongan'] ?? '';
            $kuotaRaw = $_POST['kuota'] ?? '';
            $persyaratanRaw = $_POST['persyaratan'] ?? '';
            $mulaiRaw = $_POST['tanggal_mulai'] ?? '';
            $selesaiRaw = $_POST['tanggal_selesai'] ?? '';

            if (
                !is_string($perusahaanRaw) ||
                !is_string($jurusanRaw) ||
                !is_string($judulRaw) ||
                !is_string($kuotaRaw) ||
                !is_string($persyaratanRaw) ||
                !is_string($mulaiRaw) ||
                !is_string($selesaiRaw)
            ) {
                $error = 'Format data yang dikirim tidak valid.';
            } else {
                $idPerusahaan = filter_var(
                    $perusahaanRaw,
                    FILTER_VALIDATE_INT
                );

                $idJurusan = filter_var(
                    $jurusanRaw,
                    FILTER_VALIDATE_INT
                );

                $kuota = filter_var(
                    $kuotaRaw,
                    FILTER_VALIDATE_INT
                );

                $judul = trim($judulRaw);
                $persyaratan = trim($persyaratanRaw);
                $mulai = trim($mulaiRaw);
                $selesai = trim($selesaiRaw);

                /* Pertahankan input jika validasi gagal */
                $lowongan['id_perusahaan'] =
                    $idPerusahaan !== false && $idPerusahaan !== null
                        ? $idPerusahaan
                        : 0;

                $lowongan['id_jurusan'] =
                    $idJurusan !== false && $idJurusan !== null
                        ? $idJurusan
                        : 0;

                $lowongan['judul_lowongan'] = $judul;
                $lowongan['kuota'] =
                    $kuota !== false && $kuota !== null
                        ? $kuota
                        : 0;

                $lowongan['persyaratan'] = $persyaratan;
                $lowongan['tanggal_mulai'] = $mulai;
                $lowongan['tanggal_selesai'] = $selesai;

                /* Validasi data wajib */
                if (
                    $idPerusahaan === false ||
                    $idPerusahaan === null ||
                    $idPerusahaan < 1 ||
                    $idJurusan === false ||
                    $idJurusan === null ||
                    $idJurusan < 1 ||
                    $judul === '' ||
                    mb_strlen($judul) > 150 ||
                    $kuota === false ||
                    $kuota === null ||
                    $kuota < 1 ||
                    $mulai === '' ||
                    $selesai === ''
                ) {
                    $error = 'Periksa kembali data wajib. Judul maksimal 150 karakter dan kuota minimal 1.';
                } elseif (strlen($persyaratan) > 10000) {
                    $error = 'Persyaratan terlalu panjang. Maksimal 10.000 byte.';
                } elseif (
                    !tanggalValid($mulai) ||
                    !tanggalValid($selesai)
                ) {
                    $error = 'Format tanggal tidak valid.';
                } elseif ($selesai < $mulai) {
                    $error = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
                } else {

                    /* Periksa perusahaan */
                    $cek = $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM perusahaan
                         WHERE id_perusahaan = ?"
                    );

                    $cek->execute([$idPerusahaan]);

                    if (!(int) $cek->fetchColumn()) {
                        $error = 'Perusahaan yang dipilih tidak ditemukan.';
                    } else {

                        /* Periksa jurusan */
                        $cek = $pdo->prepare(
                            "SELECT COUNT(*)
                             FROM jurusan
                             WHERE id_jurusan = ?"
                        );

                        $cek->execute([$idJurusan]);

                        if (!(int) $cek->fetchColumn()) {
                            $error = 'Jurusan yang dipilih tidak ditemukan.';
                        } else {

                            /*
                             * Perbarui data dengan transaksi.
                             * Kunci baris lowongan agar pemeriksaan
                             * kuota dan update dilakukan secara aman.
                             */
                            $pdo->beginTransaction();

                            try {
                                $lock = $pdo->prepare(
                                    "SELECT id_lowongan
                                     FROM lowongan_pkl
                                     WHERE id_lowongan = ?
                                     FOR UPDATE"
                                );

                                $lock->execute([$id]);
                                $lowonganTerkunci = $lock->fetchColumn();

                                if (!$lowonganTerkunci) {
                                    throw new RuntimeException(
                                        'Lowongan tidak ditemukan.'
                                    );
                                }

                                /* Hitung siswa yang sudah diterima */
                                $cekDiterima = $pdo->prepare(
                                    "SELECT COUNT(*)
                                     FROM pendaftaran
                                     WHERE id_lowongan = ?
                                       AND status = 'diterima'"
                                );

                                $cekDiterima->execute([$id]);
                                $jumlahDiterima = (int) $cekDiterima->fetchColumn();

                                if ($kuota < $jumlahDiterima) {
                                    $pdo->rollBack();

                                    $error = 'Kuota tidak boleh lebih kecil daripada jumlah siswa yang sudah diterima.';
                                } else {
                                    $update = $pdo->prepare(
                                        "UPDATE lowongan_pkl
                                         SET id_perusahaan = ?,
                                             id_jurusan = ?,
                                             judul_lowongan = ?,
                                             kuota = ?,
                                             persyaratan = ?,
                                             tanggal_mulai = ?,
                                             tanggal_selesai = ?
                                         WHERE id_lowongan = ?"
                                    );

                                    $update->execute([
                                        $idPerusahaan,
                                        $idJurusan,
                                        $judul,
                                        $kuota,
                                        $persyaratan,
                                        $mulai,
                                        $selesai,
                                        $id
                                    ]);

                                    $pdo->commit();

                                    /* Ganti token setelah berhasil */
                                    $_SESSION['csrf_edit_lowongan'] =
                                        bin2hex(random_bytes(32));

                                    header(
                                        'Location: kelola_lowongan.php?pesan=edit_lowongan_berhasil'
                                    );
                                    exit;
                                }
                            } catch (Throwable $e) {
                                if ($pdo->inTransaction()) {
                                    $pdo->rollBack();
                                }

                                throw $e;
                            }
                        }
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log(
        '[edit_lowongan.php] ' . $e->getMessage()
    );

    if ($error === '') {
        $error = 'Terjadi kesalahan saat memproses data. Silakan coba kembali.';
    }
}

$namaAdmin = $_SESSION['nama_lengkap'] ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Lowongan | CariTempatPKL</title>

<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#1e293b}
.layout{display:flex;min-height:100vh}
.sidebar{width:245px;flex-shrink:0;background:#123b75;color:white;padding:28px 18px}
.brand{font-size:21px;font-weight:bold;margin-bottom:8px}
.brand span{color:#7dd3fc}
.subtitle{font-size:12px;color:#cbdcf4;margin-bottom:35px}
.menu-title{font-size:11px;text-transform:uppercase;color:#a9c1e2;margin:22px 12px 10px;letter-spacing:1px}
.menu a{display:block;text-decoration:none;color:#e2ecfa;padding:13px 14px;border-radius:9px;margin-bottom:5px;font-size:14px}
.menu a:hover,.menu a.active{background:#24549a;color:white}
.main{flex:1;min-width:0}
.topbar{background:white;padding:22px 30px;display:flex;justify-content:space-between;align-items:center;gap:15px;border-bottom:1px solid #e5eaf1}
.topbar h1{font-size:23px}
.topbar p{font-size:13px;color:#64748b;margin-top:5px}
.admin-name{font-size:13px;font-weight:bold;background:#eaf2ff;color:#17458a;padding:11px 14px;border-radius:10px}
.content{padding:28px 30px;max-width:1100px;margin:0 auto}
.panel{background:white;border:1px solid #e7edf5;border-radius:14px;overflow:hidden}
.panel-head{padding:20px 22px;border-bottom:1px solid #edf1f6}
.panel-head h2{font-size:19px;margin-bottom:6px}
.panel-head p{font-size:13px;color:#64748b}
.form-grid{padding:22px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px}
.field{display:flex;flex-direction:column;gap:7px}
.field.full{grid-column:1/-1}
.field label{font-size:13px;font-weight:bold;color:#334155}
.field input,.field select,.field textarea{width:100%;padding:11px 12px;border:1px solid #d7e0ec;border-radius:8px;font:14px Arial;background:white;color:#1e293b}
.field textarea{min-height:120px;resize:vertical}
.btn{border:0;border-radius:8px;padding:11px 16px;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block}
.btn-primary{background:#1d5bbf;color:white}
.btn-primary:hover{background:#17458a}
.btn-light{background:#e8eef7;color:#334155}
.actions{grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap;margin-top:5px}
.alert{padding:13px 16px;border-radius:9px;margin-bottom:18px;font-size:13px}
.alert.error{background:#fee2e2;color:#991b1b}
.status-note{margin:18px 22px 0;padding:12px 14px;background:#f1f5f9;border-radius:8px;font-size:13px;color:#475569}
.footer{text-align:center;color:#94a3b8;font-size:12px;padding:24px 0}
@media(max-width:800px){
.layout{display:block}
.sidebar{width:100%;padding:20px}
.subtitle{margin-bottom:15px}
.menu{display:flex;flex-wrap:wrap;gap:5px}
.menu-title{display:none}
.menu a{padding:10px;font-size:12px}
.topbar{padding:20px;align-items:flex-start;flex-direction:column}
.content{padding:20px 14px}
.form-grid{grid-template-columns:1fr}
.field.full,.actions{grid-column:auto}
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
        <a href="dashboard_admin.php">▦ Dashboard</a>
        <a href="data_siswa.php">♙ Data Siswa</a>
        <a href="data_perusahaan.php">▣ Data Perusahaan</a>
        <a href="kelola_lowongan.php" class="active">⌕ Kelola Lowongan</a>
        <a href="admin_pendaftaran.php">▤ Pendaftaran</a>
        <a href="status_pendaftaran.php">◷ Status Pendaftaran</a>
    </nav>

    <div class="menu-title">Akun</div>
    <nav class="menu"><a href="logout.php">⇥ Keluar</a></nav>
</aside>

<main class="main">
<header class="topbar">
    <div>
        <h1>Edit Lowongan PKL</h1>
        <p>Perbarui informasi lowongan untuk siswa</p>
    </div>
    <div class="admin-name">Admin: <?= h($namaAdmin) ?></div>
</header>

<section class="content">
    <?php if ($error !== ''): ?>
        <div class="alert error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-head">
            <h2>Informasi Lowongan</h2>
            <p>Ubah data yang diperlukan, lalu tekan Simpan Perubahan.</p>
        </div>

        <div class="status-note">
            Status saat ini:
            <strong><?= h(ucfirst($lowongan['status'])) ?></strong>.
            Status dapat diubah dari halaman Kelola Lowongan.
        </div>

        <form method="POST" class="form-grid">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= h($csrf) ?>"
    >
            <div class="field">
                <label for="id_perusahaan">Perusahaan *</label>
                <select id="id_perusahaan" name="id_perusahaan" required>
                    <option value="">Pilih perusahaan</option>
                    <?php foreach ($perusahaanList as $p): ?>
                        <option
                            value="<?= (int) $p['id_perusahaan'] ?>"
                            <?= (int) $lowongan['id_perusahaan'] === (int) $p['id_perusahaan'] ? 'selected' : '' ?>>
                            <?= h($p['nama_perusahaan']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="id_jurusan">Jurusan yang Dibutuhkan *</label>
                <select id="id_jurusan" name="id_jurusan" required>
                    <option value="">Pilih jurusan</option>
                    <?php foreach ($jurusanList as $j): ?>
                        <option
                            value="<?= (int) $j['id_jurusan'] ?>"
                            <?= (int) $lowongan['id_jurusan'] === (int) $j['id_jurusan'] ? 'selected' : '' ?>>
                            <?= h($j['kode_jurusan'] . ' - ' . $j['nama_jurusan']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field full">
                <label for="judul_lowongan">Judul Lowongan *</label>
                <input
                    id="judul_lowongan"
                    type="text"
                    name="judul_lowongan"
                    maxlength="150"
                    required
                    value="<?= h($lowongan['judul_lowongan']) ?>">
            </div>

            <div class="field">
                <label for="kuota">Kuota Siswa *</label>
                <input
                    id="kuota"
                    type="number"
                    name="kuota"
                    min="1"
                    required
                    value="<?= (int) $lowongan['kuota'] ?>">
            </div>

            <div class="field">
                <label for="tanggal_mulai">Tanggal Mulai *</label>
                <input
                    id="tanggal_mulai"
                    type="date"
                    name="tanggal_mulai"
                    required
                    value="<?= h($lowongan['tanggal_mulai']) ?>">
            </div>

            <div class="field">
                <label for="tanggal_selesai">Tanggal Selesai *</label>
                <input
                    id="tanggal_selesai"
                    type="date"
                    name="tanggal_selesai"
                    required
                    value="<?= h($lowongan['tanggal_selesai']) ?>">
            </div>

            <div class="field full">
                <label for="persyaratan">Persyaratan</label>
                <textarea
                    id="persyaratan"
                    name="persyaratan"
                    placeholder="Tuliskan persyaratan siswa..."><?= h($lowongan['persyaratan']) ?></textarea>
            </div>

            <div class="actions">
                <button class="btn btn-primary" type="submit">
                    Simpan Perubahan
                </button>
                <a class="btn btn-light" href="kelola_lowongan.php">Batal</a>
            </div>
        </form>
    </section>

    <div class="footer">
        © <?= date('Y') ?> CariTempatPKL — Panel Administrasi
    </div>
</section>
</main>
</div>
</body>
</html>
