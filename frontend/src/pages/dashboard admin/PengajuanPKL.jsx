import { useEffect, useState } from "react";
import { applicationsApi, usersApi, companiesApi, profilesApi } from "../../api/index.js";
import { SkelCards } from "../../components/role/skeleton.jsx";
import "./admin-pages.css";

const BULAN = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
const fmtDate = (iso) => {
  if (!iso) return "-";
  const s = String(iso).slice(0, 10);
  const [y, m, d] = s.split("-").map(Number);
  if (!y || !m || !d || !BULAN[m - 1]) return s;
  return `${d} ${BULAN[m - 1]} ${y}`;
};

function initials(name) {
  const parts = String(name || "?").trim().split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function Persetujuan({ onMeta }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const [appsRes, usersRes, companiesRes, profilesRes] = await Promise.all([
        applicationsApi.index({ status: "accepted" }),
        usersApi.index(),
        companiesApi.index(),
        profilesApi.index().catch(() => ({ data: [] })),
      ]);
      const apps = Array.isArray(appsRes.data) ? appsRes.data : [];
      const users = Array.isArray(usersRes.data) ? usersRes.data : [];
      const companies = Array.isArray(companiesRes.data) ? companiesRes.data : [];
      const profiles = Array.isArray(profilesRes.data) ? profilesRes.data : [];
      const userMap = Object.fromEntries(users.map((u) => [u.id, u]));
      const companyMap = Object.fromEntries(companies.map((c) => [c.id, c.name]));
      const profileMap = Object.fromEntries(profiles.map((p) => [p.user_id, p]));
      apps.sort((a, b) => new Date(a.updated_at || a.created_at) - new Date(b.updated_at || b.created_at));
      setRows(
        apps.map((a) => {
          const u = userMap[a.student_id] || {};
          const prof = profileMap[a.student_id] || {};
          return {
            ...a,
            siswa: u.name || `Siswa #${a.student_id}`,
            kelas: prof.kelas || prof.class || "-",
            perusahaan: companyMap[a.company_id] || `Perusahaan #${a.company_id}`,
            tglDiterima: fmtDate(a.updated_at || a.created_at),
            guru: a.teacher_name || "-",
          };
        })
      );
    } catch (err) {
      setError(err?.message || "Gagal memuat persetujuan.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
    onMeta?.({ title: "Monitoring Penempatan", subtitle: "Pantau penempatan resmi yang dibuat otomatis" });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="zip-page">
      {error && <div className="zip-error">{error}</div>}

      {rows.length > 0 && (
        <div className="zip-warn" style={{ display: "flex", alignItems: "center", gap: 12 }}>
          <span style={{ width: 36, height: 36, borderRadius: 10, background: "#d97706", color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
            <i className="fa-solid fa-triangle-exclamation"></i>
          </span>
          <div>
            <strong>{rows.length} lamaran diterima menunggu pilihan final siswa.</strong>{" "}
            Penempatan resmi dibuat otomatis saat siswa memilih tawaran — tanpa persetujuan admin.
          </div>
        </div>
      )}

      <div className="zip-card" style={{ maxWidth: "none" }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 6 }}>
          <div>
            <h3 className="zip-card-title" style={{ margin: 0 }}>Lamaran Diterima Perusahaan</h3>
            <div className="zip-sub">Menunggu pilihan final siswa — penempatan dibuat otomatis</div>
          </div>
          {rows.length > 0 && <span className="zip-badge b-amber">● {rows.length} menunggu</span>}
        </div>

        {loading ? (
          <SkelCards n={3} />
        ) : rows.length === 0 ? (
          <p className="zip-muted">Tidak ada lamaran diterima yang menunggu pilihan siswa.</p>
        ) : (
          <div className="zip-table-wrap" style={{ boxShadow: "none" }}>
            <table className="zip-table">
              <thead>
                <tr>
                  <th>Siswa</th>
                  <th>Perusahaan</th>
                  <th>Tgl Diterima</th>
                  <th>Guru Pembimbing</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td>
                      <span className="zip-user-cell">
                        <span className="zip-avatar" style={{ background: "#0ea5e9" }}>{initials(r.siswa)}</span>
                        <span>
                          <div className="zip-user-name">{r.siswa}</div>
                          <div className="zip-user-email">{r.kelas}</div>
                        </span>
                      </span>
                    </td>
                    <td>{r.perusahaan}</td>
                    <td><span className="zip-sub">{r.tglDiterima}</span></td>
                    <td><span className="zip-sub">{r.guru}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="zip-note" style={{ marginTop: 14, marginBottom: 0 }}>
          ℹ️ Penempatan resmi dibuat otomatis saat siswa memilih tawaran — siswa, guru pembimbing, dan pembimbing industri langsung terhubung.
        </div>
      </div>
    </div>
  );
}

export default Persetujuan;
