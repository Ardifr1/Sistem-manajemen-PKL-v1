import { useState } from "react";
import { Link } from "react-router-dom";
import "./login.css";
import { authApi } from "../../api/index.js";

const BULLETS = [
  "Pengajuan maks. 3 perusahaan, terkunci setelah kirim",
  "Jurnal + AI Assistant, verifikasi Industri",
  "Monitoring Guru, penilaian & nilai akhir",
];

function LupaPassword() {
  const [login, setLogin] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [sent, setSent] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    if (!login.trim()) {
      setError("Masukkan email atau username akun kamu.");
      return;
    }
    setLoading(true);
    try {
      const res = await authApi.forgotPassword(login.trim());
      setSent(true);
      setError("");
      void res;
    } catch (err) {
      setError(err?.message || "Gagal mengirim permintaan. Coba lagi.");
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
          Lupa password?
          <br />
          Tenang, bisa diurus.
        </h1>
        <p className="login-desc">
          Masukkan email atau username akun SiMagang kamu. Permintaan reset akan
          diteruskan ke admin sekolah.
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
          <h2 className="login-title">Lupa Password</h2>
          <p className="login-subtitle">
            Kami akan meneruskan permintaanmu ke admin sekolah untuk dibuatkan password baru.
          </p>

          {sent ? (
            <div>
              <div className="login-success" role="status">
                Permintaan terkirim. Hubungi admin sekolah (TU) untuk mendapatkan
                password baru kamu.
              </div>
              <Link to="/login" className="btn-primary" style={{ display: "block", textAlign: "center", textDecoration: "none", marginTop: 16 }}>
                Kembali ke Login
              </Link>
            </div>
          ) : (
            <form className="login-form" onSubmit={handleSubmit} noValidate>
              <div className="field">
                <label htmlFor="fp-id">Email / Username</label>
                <div className="input-wrap">
                  <input
                    id="fp-id"
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

              <button type="submit" className="btn-primary" disabled={loading}>
                {loading ? "Mengirim..." : "Kirim Permintaan Reset"}
              </button>

              <p className="roles-note">
                <Link to="/login" className="forgot">Kembali ke halaman login</Link>
              </p>

              {error && (
                <div className="login-error" role="alert">
                  {error}
                </div>
              )}
            </form>
          )}
        </div>
      </main>
    </div>
  );
}

export default LupaPassword;
