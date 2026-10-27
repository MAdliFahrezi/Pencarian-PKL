
<?php
session_start();
require_once 'koneksi.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';

    if (!is_string($emailInput) || !is_string($passwordInput)) {
        $error = 'Email atau password salah.';
    } else {
        $email = trim($emailInput);
        $password = $passwordInput;

        if (
            $email === '' ||
            $password === '' ||
            strlen($email) > 254 ||
            strlen($password) > 4096
        ) {
            $error = 'Email atau password salah.';
        } else {
            $sql = "
                SELECT
                    p.id_pengguna,
                    p.nama_lengkap,
                    p.password_hash,
                    p.peran,
                    s.id_siswa
                FROM pengguna p
                LEFT JOIN siswa s
                    ON s.id_pengguna = p.id_pengguna
                WHERE p.email = ?
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $peranDiizinkan = [
                'siswa',
                'guru',
                'koordinator',
                'perusahaan',
                'admin'
            ];

            if (
                $user &&
                is_string($user['password_hash']) &&
                password_verify($password, $user['password_hash']) &&
                in_array($user['peran'], $peranDiizinkan, true)
            ) {
                if (
                    $user['peran'] === 'siswa' &&
                    empty($user['id_siswa'])
                ) {
                    $error = 'Akun siswa belum terhubung dengan data siswa. Hubungi pengelola PKL.';
                } else {
                    session_regenerate_id(true);

                    unset(
                        $_SESSION['id_pengguna'],
                        $_SESSION['id_siswa'],
                        $_SESSION['nama_lengkap'],
                        $_SESSION['peran']
                    );

                    $_SESSION['id_pengguna'] = (int) $user['id_pengguna'];
                    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                    $_SESSION['peran'] = $user['peran'];

                    if ($user['peran'] === 'siswa') {
                        $_SESSION['id_siswa'] = (int) $user['id_siswa'];
                        header('Location: cari_perusahaan.php');
                    } elseif ($user['peran'] === 'guru') {
                        header('Location: dashboard_guru.php');
                    } elseif ($user['peran'] === 'koordinator') {
                        header('Location: dashboard_koordinator.php');
                    } elseif ($user['peran'] === 'perusahaan') {
                        header('Location: dashboard_perusahaan.php');
                    } elseif ($user['peran'] === 'admin') {
                        header('Location: dashboard_admin.php');
                    }

                    exit;
                }
            } else {
                $error = 'Email atau password salah.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Login | CariTempatPKL</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, Helvetica, sans-serif;
            color: #172744;
            background: #f3f6fc;
        }

        .login-wrapper {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 1fr;
        }

        .login-banner {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 65px;
            overflow: hidden;
            color: white;
            background: linear-gradient(135deg, #153b9e, #3475f5);
        }

        .login-banner::before,
        .login-banner::after {
            content: "";
            position: absolute;
            border: 1px solid rgba(255,255,255,.16);
            border-radius: 50%;
        }

        .login-banner::before {
            width: 390px;
            height: 390px;
            right: -130px;
            top: -100px;
        }

        .login-banner::after {
            width: 280px;
            height: 280px;
            right: -60px;
            bottom: -90px;
        }

        .brand {
            position: absolute;
            top: 35px;
            left: 45px;
            display: flex;
            align-items: center;
            gap: 11px;
            color: white;
            font-size: 22px;
            font-weight: 800;
            text-decoration: none;
        }

        .brand-mark {
            display: grid;
            place-items: center;
            width: 45px;
            height: 45px;
            border-radius: 13px;
            color: #2457d6;
            background: white;
            font-size: 14px;
        }

        .banner-content {
            position: relative;
            z-index: 1;
            max-width: 490px;
        }

        .banner-label {
            display: inline-block;
            padding: 9px 13px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 30px;
            background: rgba(255,255,255,.10);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .banner-content h1 {
            margin: 0 0 20px;
            font-size: clamp(32px, 4vw, 49px);
            line-height: 1.2;
        }

        .banner-content p {
            max-width: 420px;
            color: #e2eaff;
            font-size: 16px;
            line-height: 1.9;
        }

        .banner-features {
            display: grid;
            gap: 15px;
            margin-top: 30px;
        }

        .feature {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        }

        .feature-icon {
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: rgba(255,255,255,.15);
        }

        .login-side {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 25px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 38px;
            border: 1px solid #e5eaf4;
            border-radius: 22px;
            background: white;
            box-shadow: 0 18px 55px rgba(27, 55, 110, .08);
        }

        .mobile-brand {
            display: none;
        }

        .login-heading {
            margin-bottom: 28px;
        }

        .login-heading .eyebrow {
            color: #2c5ce5;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1.4px;
        }

        .login-heading h2 {
            margin: 12px 0 9px;
            color: #172744;
            font-size: 29px;
        }

        .login-heading p {
            margin: 0;
            color: #7785a0;
            font-size: 14px;
            line-height: 1.7;
        }

        .alert {
            padding: 13px 15px;
            margin-bottom: 20px;
            border-radius: 10px;
            color: #b42323;
            background: #ffe8e8;
            font-size: 13px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 9px;
            color: #273752;
            font-size: 13px;
            font-weight: 700;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            color: #8997b0;
            font-size: 17px;
            pointer-events: none;
        }

        .form-group input {
            width: 100%;
            height: 49px;
            padding: 0 14px 0 44px;
            border: 1px solid #dce4f1;
            border-radius: 11px;
            outline: none;
            background: #fbfcff;
            color: #172744;
            font-size: 14px;
            transition: border-color .2s, box-shadow .2s;
        }

        .form-group input:focus {
            border-color: #3470f2;
            box-shadow: 0 0 0 4px rgba(52,112,242,.10);
            background: white;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #63718a;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
        }

        .form-group input[type="password"],
        .form-group input[type="text"] {
            padding-right: 60px;
        }

        .login-button {
            width: 100%;
            min-height: 49px;
            margin-top: 5px;
            border: 0;
            border-radius: 11px;
            color: white;
            background: linear-gradient(120deg, #2452d0, #3475f5);
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            transition: transform .2s, box-shadow .2s;
        }

        .login-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(44,92,229,.23);
        }

        .login-footer {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #edf0f6;
            color: #8995aa;
            text-align: center;
            font-size: 12px;
            line-height: 1.7;
        }

        @media (max-width: 850px) {
            .login-wrapper {
                grid-template-columns: 1fr;
            }

            .login-banner {
                display: none;
            }

            .login-side {
                min-height: 100vh;
                padding: 25px 18px;
            }

            .login-card {
                max-width: 440px;
                padding: 30px 25px;
            }

            .mobile-brand {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                margin-bottom: 30px;
                color: #172744;
                font-size: 21px;
                font-weight: 800;
            }

            .mobile-brand .brand-mark {
                color: white;
                background: #2457d6;
            }
        }

        @media (max-width: 400px) {
            .login-card {
                padding: 25px 19px;
            }

            .login-heading h2 {
                font-size: 25px;
            }
        }
    </style>
</head>

<body>
<div class="login-wrapper">

    <section class="login-banner">
        <a href="cari_perusahaan.php" class="brand">
            <span class="brand-mark">PKL</span>
            <span>CariTempat<span style="color:#cbd9ff">PKL</span></span>
        </a>

        <div class="banner-content">
            <span class="banner-label">PORTAL PKL SEKOLAH</span>

            <h1>Mulai perjalanan PKL-mu bersama kami.</h1>

            <p>
                Temukan perusahaan yang sesuai dengan jurusanmu,
                ajukan pendaftaran, dan pantau perkembangan PKL
                melalui satu platform.
            </p>

            <div class="banner-features">
                <div class="feature">
                    <span class="feature-icon">✓</span>
                    <span>Temukan tempat PKL sesuai jurusan</span>
                </div>

                <div class="feature">
                    <span class="feature-icon">✓</span>
                    <span>Pantau status pendaftaran dengan mudah</span>
                </div>

                <div class="feature">
                    <span class="feature-icon">✓</span>
                    <span>Akses layanan PKL dalam satu sistem</span>
                </div>
            </div>
        </div>
    </section>

    <main class="login-side">
        <div class="login-card">

            <div class="mobile-brand">
                <span class="brand-mark">PKL</span>
                <span>CariTempat<span style="color:#2c5ce5">PKL</span></span>
            </div>

            <div class="login-heading">
                <span class="eyebrow">SELAMAT DATANG</span>
                <h2>Masuk ke akun</h2>
                <p>
                    Masukkan email dan password untuk melanjutkan
                    ke dashboard CariTempatPKL.
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert" role="alert">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="form-group">
                    <label for="email">Email</label>

                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">✉</span>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="nama@email.com"
                            autocomplete="username"
                            maxlength="254"
                            value="<?= htmlspecialchars(
                                $_SERVER['REQUEST_METHOD'] === 'POST'
                                    && isset($_POST['email'])
                                    && is_string($_POST['email'])
                                    ? $_POST['email']
                                    : '',
                                ENT_QUOTES | ENT_SUBSTITUTE,
                                'UTF-8'
                            ) ?>"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>

                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">▣</span>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            id="togglePassword"
                            aria-label="Tampilkan password"
                        >
                            Lihat
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-button">
                    Masuk ke akun →
                </button>
            </form>

            <div class="login-footer">
                © <?= date('Y') ?> CariTempatPKL<br>
                Sistem Informasi Pencarian Tempat PKL
            </div>
        </div>
    </main>

</div>

<script>
const togglePassword = document.getElementById('togglePassword');
const passwordInput = document.getElementById('password');

togglePassword.addEventListener('click', function () {
    const isPassword = passwordInput.type === 'password';

    passwordInput.type = isPassword ? 'text' : 'password';
    togglePassword.textContent = isPassword ? 'Sembunyi' : 'Lihat';

    togglePassword.setAttribute(
        'aria-label',
        isPassword ? 'Sembunyikan password' : 'Tampilkan password'
    );
});
</script>

</body>
</html>
```