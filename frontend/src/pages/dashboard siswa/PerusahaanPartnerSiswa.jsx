import { useEffect, useState } from "react";
import { companiesApi } from "../../api/index.js";
import { DetailPerusahaan } from "../../components/role/detail-perusahaan.jsx";

/**
 * Daftar perusahaan mitra. Prop `readonly` dipakai untuk arsip fase 2.
 */
function PerusahaanPartnerSiswa({ onMeta, readonly = false }) {
  const [companies, setCompanies] = useState([]);
  const [q, setQ] = useState("");
  const [loading, setLoading] = useState(true);
  const [detail, setDetail] = useState(null);

  useEffect(() => {
    onMeta?.({ title: "Perusahaan Partner", subtitle: readonly ? "Arsip — read only" : "Daftar perusahaan mitra sekolah" });
    (async () => {
      try {
        const res = await companiesApi.index({ is_partner: true }).catch(() => ({ data: [] }));
        setCompanies(res?.data ?? []);
      } finally { setLoading(false); }
    })();
  }, [onMeta, readonly]);

  const list = companies.filter((c) =>
    !q || c.name?.toLowerCase().includes(q.toLowerCase()) || c.industry?.toLowerCase().includes(q.toLowerCase())
  );

  return (
    <div>
      {readonly && (
        <div className="siswa-banner gray">
          <span className="b-ic"><i className="fa-solid fa-lock"></i></span>
          <div>PKL-mu sudah aktif. Daftar perusahaan dikunci sebagai arsip.</div>
        </div>
      )}
      <div className="siswa-card">
        <div className="siswa-between" style={{ marginBottom: 16 }}>
          <h3 className="siswa-card-title">{companies.length} Perusahaan Mitra</h3>
          <span style={{ position: "relative" }}>
            <i className="fa-solid fa-magnifying-glass" style={{ position: "absolute", left: 14, top: 13, color: "#94a3b8", fontSize: 13 }}></i>
            <input className="siswa-input siswa-search" style={{ paddingLeft: 36, width: 240 }} placeholder="Cari perusahaan…"
              value={q} onChange={(e) => setQ(e.target.value)} />
          </span>
        </div>
        {loading ? <p className="siswa-muted">Memuat…</p> : list.length === 0 ? (
          <div className="siswa-empty"><i className="fa-solid fa-building"></i>Tidak ada perusahaan ditemukan.</div>
        ) : (
          <div className="siswa-grid2">
            {list.map((c) => {
              const pct = c.student_quota ? Math.round(((c._terisi || 0) / c.student_quota) * 100) : 0;
              return (
                <div key={c.id} className="siswa-co-card">
                  <div className="siswa-co" style={{ marginBottom: 10 }}>
                    <span className="siswa-co-logo">{String(c.name || "?").charAt(0)}</span>
                    <div className="siswa-co-tx">
                      <b>{c.name}</b>
                      <small>{c.industry} • {c.address}</small>
                    </div>
                  </div>
                  <div className="siswa-muted" style={{ fontSize: 12 }}>
                    Kuota: {c._terisi || 0}/{c.student_quota || "—"}
                    {c.required_skills ? ` • ${c.required_skills}` : ""}
                  </div>
                  <div className="siswa-quota"><i style={{ width: `${Math.min(pct, 100)}%` }}></i></div>
                  <div className="siswa-between siswa-mt">
                    <span className={`siswa-badge ${pct >= 100 ? "s-badge-red" : "s-badge-green"}`}>
                      <span className="dot"></span>{pct >= 100 ? "Penuh" : "Tersedia"}
                    </span>
                    <button type="button" className="siswa-btn siswa-btn-outline siswa-btn-sm"
                      onClick={() => setDetail(c)}>
                      Detail
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
      {detail && <DetailPerusahaan c={detail} onClose={() => setDetail(null)} hideAjukan />}
    </div>
  );
}

export default PerusahaanPartnerSiswa;
