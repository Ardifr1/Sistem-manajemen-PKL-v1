import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import "./login.css";
import { authApi } from "../../api/index.js";

const BULLETS = [
  "Pengajuan maks. 3 perusahaan, terkunci setelah kirim",
  "Jurnal + AI Assistant, verifikasi Industri",
  "Monitoring Guru, penilaian & nilai akhir",
];

function Login() {
  const navigate = useNavigate();
  const [login, setLogin] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [remember, setRemember] = useState(true);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    // Pangkas spasi di awal/akhir: menempel email/password dari WhatsApp
    // sering ikut membawa spasi/baris baru yang tak terlihat, dan backend
    // membandingkan password secara persis sehingga satu spasi saja
    // membuat login ditolak.
    const loginId = login.trim();
    const pass = password.trim();
    if (!loginId || !pass) {
      setError("Email atau password salah. Coba lagi.");
      return;
    }
    setLoading(true);
    try {
      const res = await authApi.login({ email: loginId, password: pass });
      if (res?.data?.user?.must_change_password) {
        navigate("/ganti-password", { replace: true });
      } else {
        navigate("/", { replace: true });
      }
    } catch (err) {
      setError(err?.message || "Email atau password salah. Coba lagi.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      {/* ============ LEFT PANEL ============ */}
      <aside className="login-left">
        <div className="login-brand">
          <img className="login-logo" src="/logo-simagang.png" alt="Logo SiMagang" />
          <span className="login-brand-text">
            <strong>SiMagang</strong>
            <small>Sistem Informasi Magang</small>
          </span>
        </div>

        <h1 className="login-headline">
          Kelola PKL satu sekolah
          <br />
          dalam satu tempat.
        </h1>
        <p className="login-desc">
          Persiapan, pengajuan, penempatan, jurnal, monitoring, hingga nilai
          akhir — terpusat untuk Siswa, Guru, Pembimbing Industri, dan Admin.
        </p>

        <ul className="login-bullets">
          {BULLETS.map((b) => (
            <li key={b}>
              <span className="login-bullet-dot" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none">
                  <path
                    d="m5 12.5 4.5 4.5L19 7.5"
                    stroke="#fff"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </span>
              {b}
            </li>
          ))}
        </ul>

        <p className="login-foot">SMK Negeri 1 • Periode PKL 2026: 1 Agu – 31 Okt 2026</p>
      </aside>

      {/* ============ RIGHT PANEL ============ */}
      <main className="login-right">
        <div className="login-form-wrap">
          <h2 className="login-title">Masuk ke SiMagang</h2>
          <p className="login-subtitle">
            Masukkan akun sekolah. Sistem otomatis mengarahkan ke dashboard sesuai role.
          </p>

          <form className="login-form" onSubmit={handleSubmit} noValidate>
            <div className="field">
              <label htmlFor="login-id">Email / Username</label>
              <div className="input-wrap">
                <input
                  id="login-id"
                  type="text"
                  autoComplete="username"
                  placeholder="cth: andini.xiirpl2@smk.sch.id"
                  value={login}
                  onChange={(e) => setLogin(e.target.value)}
                />
                <span className="input-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none">
                    <circle cx="12" cy="8" r="3.4" stroke="#94a3b8" strokeWidth="1.8" />
                    <path
                      d="M5 19.5c1.2-3.2 3.9-4.8 7-4.8s5.8 1.6 7 4.8"
                      stroke="#94a3b8"
                      strokeWidth="1.8"
                      strokeLinecap="round"
                    />
                  </svg>
                </span>
              </div>
            </div>

            <div className="field">
              <label htmlFor="login-password">Password</label>
              <div className="input-wrap">
                <input
                  id="login-password"
                  type={showPassword ? "text" : "password"}
                  autoComplete="current-password"
                  placeholder="••••••••"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
                <button
                  type="button"
                  className="toggle-pass"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? "Sembunyikan password" : "Tampilkan password"}
                >
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true">
                    <path
                      d="M2.5 12S6.2 5 12 5s9.5 7 9.5 7-3.7 7-9.5 7-9.5-7-9.5-7Z"
                      stroke="#94a3b8"
                      strokeWidth="1.8"
                      strokeLinejoin="round"
                    />
                    <circle cx="12" cy="12" r="2.6" stroke="#94a3b8" strokeWidth="1.8" />
                  </svg>
                </button>
              </div>
            </div>

            <div className="form-row">
              <label className="remember">
                <input
                  type="checkbox"
                  checked={remember}
                  onChange={(e) => setRemember(e.target.checked)}
                />
                Ingat saya
              </label>
              <Link className="forgot" to="/lupa-password">
                Lupa password?
              </Link>
            </div>

            <button type="submit" className="btn-primary" disabled={loading}>
              {loading ? "Memeriksa..." : "Masuk"}
            </button>

            <p className="roles-note">
              Siswa • Guru Pembimbing • Pembimbing Industri • Admin — tanpa pilih role manual.
            </p>

            {error && (
              <div className="login-error" role="alert">
                {error}
              </div>
            )}
          </form>
        </div>
      </main>
    </div>
  );
}

export default Login;
