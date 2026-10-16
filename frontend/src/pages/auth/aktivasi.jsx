import { useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import "./login.css";
import { authApi } from "../../api/index.js";

/**
 * Halaman Aktivasi Akun.
 *
 * Dibuka dari tautan aktivasi yang dikirim admin via email/WhatsApp. Tautan
 * memuat `email` dan `token`; password diatur oleh pengguna sendiri (tidak
 * pernah dikirim sebagai teks biasa lewat email/WhatsApp).
 */
function Aktivasi() {
  const [params] = useSearchParams();
  const navigate = useNavigate();

  const email = params.get("email") || "";
  const token = params.get("token") || "";

  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [done, setDone] = useState(false);

  const validLink = Boolean(email && token);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    if (!validLink) {
      setError("Tautan aktivasi tidak lengkap atau sudah tidak berlaku.");
      return;
    }
    if (password.length < 8) {
      setError("Password minimal 8 karakter.");
      return;
    }
    if (password !== confirm) {
      setError("Konfirmasi password tidak cocok.");
      return;
    }
    setLoading(true);
    try {
      await authApi.setPassword({ email, token, password, passwordConfirmation: confirm });
      setDone(true);
    } catch (err) {
      setError(err?.message || "Gagal mengatur password. Tautan mungkin sudah kadaluarsa.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      <main className="login-right" style={{ gridColumn: "1 / -1" }}>
        <div className="login-form-wrap">
          <h2 className="login-title">Aktivasi Akun SiMagang</h2>
          <p className="login-subtitle">
            Atur password Anda untuk mengaktifkan akun. Kami tidak pernah mengirim
            password melalui email atau WhatsApp.
          </p>

          {done ? (
            <div>
              <div className="login-success" role="status" style={{ marginBottom: 16 }}>
                Password berhasil diatur. Silakan masuk menggunakan email <b>{email}</b>.
              </div>
              <button type="button" className="btn-primary" onClick={() => navigate("/login", { replace: true })}>
                Masuk Sekarang
              </button>
            </div>
          ) : (
            <form className="login-form" onSubmit={handleSubmit} noValidate>
              <div className="field">
                <label htmlFor="akt-email">Email</label>
                <div className="input-wrap">
                  <input id="akt-email" type="email" value={email} readOnly />
                </div>
              </div>

              <div className="field">
                <label htmlFor="akt-password">Password Baru</label>
                <div className="input-wrap">
                  <input
                    id="akt-password"
                    type="password"
                    autoComplete="new-password"
                    placeholder="Minimal 8 karakter"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                  />
                </div>
              </div>

              <div className="field">
                <label htmlFor="akt-confirm">Ulangi Password</label>
                <div className="input-wrap">
                  <input
                    id="akt-confirm"
                    type="password"
                    autoComplete="new-password"
                    placeholder="Ulangi password"
                    value={confirm}
                    onChange={(e) => setConfirm(e.target.value)}
                  />
                </div>
              </div>

              <button type="submit" className="btn-primary" disabled={loading || !validLink}>
                {loading ? "Menyimpan..." : "Aktifkan Akun"}
              </button>

              {!validLink && (
                <div className="login-error" role="alert">
                  Tautan aktivasi tidak lengkap. Minta admin sekolah mengirim ulang informasi akun.
                </div>
              )}
              {error && (
                <div className="login-error" role="alert">
                  {error}
                </div>
              )}

              <p className="roles-note" style={{ marginTop: 12 }}>
                <Link className="forgot" to="/login">Kembali ke halaman masuk</Link>
              </p>
            </form>
          )}
        </div>
      </main>
    </div>
  );
}

export default Aktivasi;
