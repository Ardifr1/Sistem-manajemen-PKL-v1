import { useState } from "react";
import { useNavigate } from "react-router-dom";
import "./login.css";
import { authApi } from "../../api/index.js";

function GantiPassword() {
  const navigate = useNavigate();
  const [current, setCurrent] = useState("");
  const [password, setPassword] = useState("");
  const [password2, setPassword2] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  // Alur paksa (login pertama): pengguna baru saja masuk memakai password
  // sementaranya, jadi field "Password Saat Ini" tidak ditampilkan —
  // cukup password baru + konfirmasi. Di luar alur paksa field tetap ada.
  const forced = !!authApi.currentUser()?.must_change_password;

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    if (!forced && !current) {
      setError("Masukkan password saat ini.");
      return;
    }
    if (password.length < 8) {
      setError("Password baru minimal 8 karakter.");
      return;
    }
    if (password !== password2) {
      setError("Konfirmasi password tidak cocok.");
      return;
    }
    setLoading(true);
    try {
      await authApi.changePassword(forced ? "" : current, password);
      navigate("/", { replace: true });
    } catch (err) {
      setError(err?.message || "Gagal mengganti password.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      <aside className="login-left">
        <div className="login-brand">
          <img className="login-logo" src="/logo-simagang.png" alt="Logo SiMagang" />
          <span className="login-brand-text">
            <strong>SiMagang</strong>
            <small>Sistem Informasi Magang</small>
          </span>
        </div>
        <h1 className="login-headline">Ganti Password</h1>
        <p className="login-desc">
          Demi keamanan, kamu wajib mengganti password sementara ini
          sebelum dapat menggunakan SiMagang.
        </p>
      </aside>
      <main className="login-right">
        <div className="login-form-wrap">
          <h2 className="login-title">Password Baru Wajib</h2>
          <form className="login-form" onSubmit={handleSubmit} noValidate>
            {!forced && (
              <div className="field">
                <label htmlFor="gp-current">Password Saat Ini</label>
                <div className="input-wrap">
                  <input id="gp-current" type="password" autoComplete="current-password"
                    value={current} onChange={(e) => setCurrent(e.target.value)} />
                </div>
              </div>
            )}
            <div className="field">
              <label htmlFor="gp-new">Password Baru</label>
              <div className="input-wrap">
                <input id="gp-new" type="password" autoComplete="new-password"
                  placeholder="Minimal 8 karakter" value={password}
                  onChange={(e) => setPassword(e.target.value)} />
              </div>
            </div>
            <div className="field">
              <label htmlFor="gp-new2">Konfirmasi Password Baru</label>
              <div className="input-wrap">
                <input id="gp-new2" type="password" autoComplete="new-password"
                  value={password2} onChange={(e) => setPassword2(e.target.value)} />
              </div>
            </div>
            <button type="submit" className="btn-primary" disabled={loading}>
              {loading ? "Menyimpan..." : "Simpan Password Baru"}
            </button>
            {error && <div className="login-error" role="alert">{error}</div>}
          </form>
        </div>
      </main>
    </div>
  );
}

export default GantiPassword;
