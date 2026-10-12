import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { companiesApi, applicationsApi, authApi } from "../../api/index.js";
import { SkelCards } from "../../components/role/skeleton.jsx";
import { DetailPerusahaan } from "../../components/role/detail-perusahaan.jsx";

/* ================= PILIH PERUSAHAAN ================= */
function PilihPerusahaan({ onMeta }) {
  const navigate = useNavigate();
  const [companies, setCompanies] = useState([]);
  const [apps, setApps] = useState([]);
  const [q, setQ] = useState("");
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(null);
  const [msg, setMsg] = useState("");
  const [detail, setDetail] = useState(null);

  useEffect(() => {
    onMeta?.({ title: "Pilih Perusahaan", subtitle: "Tentukan tempat PKL impianmu" });
    (async () => {
      try {
        const [cRes, aRes] = await Promise.all([
          companiesApi.index({ is_partner: true }).catch(() => ({ data: [] })),
          applicationsApi.index().catch(() => ({ data: [] })),
        ]);
        setCompanies(cRes?.data ?? []);
        setApps(aRes?.data ?? []);
      } finally { setLoading(false); }
    })();
  }, [onMeta]);

  const sudahDiajukan = (id) => apps.some((a) => Number(a.company_id) === Number(id));

  const ajukan = async (c) => {
    const user = authApi.currentUser();
    setSending(c.id);
    setMsg("");
    try {
      await applicationsApi.store({
        student_id: user?.id, company_id: c.id, choice_order: apps.length + 1, status: "submitted",
      });
      const aRes = await applicationsApi.index().catch(() => ({ data: [] }));
      setApps(aRes?.data ?? []);
      setMsg(`Pengajuan ke ${c.name} terkirim.`);
    } catch (e) {
      setMsg(e?.message || "Gagal mengirim pengajuan.");
    } finally { setSending(null); }
  };

  const list = companies.filter((c) =>
    !q || c.name?.toLowerCase().includes(q.toLowerCase()) || c.industry?.toLowerCase().includes(q.toLowerCase())
  );

  return (
    <div>
      {msg && <div className="siswa-ok">{msg}</div>}
      <div className="siswa-card">
        <div className="siswa-between" style={{ marginBottom: 16 }}>
          <h3 className="siswa-card-title">Perusahaan Mitra Tersedia</h3>
          <span style={{ position: "relative" }}>
            <i className="fa-solid fa-magnifying-glass" style={{ position: "absolute", left: 14, top: 13, color: "#94a3b8", fontSize: 13 }}></i>
            <input className="siswa-input" style={{ paddingLeft: 36, width: 240 }} placeholder="Cari perusahaan…"
              value={q} onChange={(e) => setQ(e.target.value)} />
          </span>
        </div>
        {loading ? <SkelCards n={3} /> : list.length === 0 ? (
          <div className="siswa-empty"><i className="fa-solid fa-building"></i>Tidak ada perusahaan ditemukan.</div>
        ) : (
          <div className="siswa-grid2">
            {list.map((c) => {
              const pct = c.student_quota ? Math.round(((c._terisi || 0) / c.student_quota) * 100) : 0;
              const diajukan = sudahDiajukan(c.id);
              return (
                <div className="siswa-card" style={{ marginBottom: 0, border: "1px solid #eef2f7" }} key={c.id}>
                  <div className="siswa-co" style={{ marginBottom: 10 }}>
                    <span className="siswa-co-logo">{String(c.name || "?").charAt(0)}</span>
                    <div className="siswa-co-tx">
                      <b>{c.name}</b>
                      <small>{c.industry} • {c.address}</small>
                    </div>
                  </div>
                  <div className="siswa-muted" style={{ fontSize: 12, marginBottom: 4 }}>
                    Kuota: {c._terisi || 0}/{c.student_quota || "—"} • {c.required_skills || ""}
                  </div>
                  <div className="siswa-quota"><i style={{ width: `${Math.min(pct, 100)}%` }}></i></div>
                  <div className="siswa-between siswa-mt">
                    <span className={`siswa-badge ${pct >= 100 ? "s-badge-red" : "s-badge-green"}`}>
                      <span className="dot"></span>{pct >= 100 ? "Penuh" : "Tersedia"}
                    </span>
                    <div style={{ display: "flex", gap: 8 }}>
                      <button type="button" className="siswa-btn siswa-btn-outline siswa-btn-sm"
                        onClick={() => setDetail(c)}>
                        Detail
                      </button>
                      {diajukan ? (
                        <span className="siswa-badge s-badge-blue"><span className="dot"></span>Sudah Diajukan</span>
                      ) : (
                        <button type="button" className="siswa-btn siswa-btn-primary siswa-btn-sm"
                          disabled={sending === c.id || pct >= 100}
                          onClick={() => ajukan(c)}>
                          {sending === c.id ? "Mengirim…" : "Ajukan"}
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
      {detail && <DetailPerusahaan c={detail} onClose={() => setDetail(null)} onAjukan={(c) => { setDetail(null); ajukan(c); }} diajukan={sudahDiajukan(detail.id)} sending={sending === detail.id} />}
      <div style={{ textAlign: "center" }}>
        <button type="button" className="siswa-btn siswa-btn-outline" onClick={() => navigate("/siswa/pengajuan")}>
          Lihat Pengajuanku <i className="fa-solid fa-arrow-right"></i>
        </button>
      </div>
    </div>
  );
}

/* ================= PILIH FINAL ================= */
export function PilihFinal({ onMeta }) {
  const navigate = useNavigate();
  const [apps, setApps] = useState([]);
  const [companies, setCompanies] = useState([]);
  const [dipilih, setDipilih] = useState(null);
  const [loading, setLoading] = useState(true);
  const [confirm, setConfirm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState(false);

  useEffect(() => {
    onMeta?.({ title: "Pilih Final", subtitle: "Tentukan satu perusahaan pilihan terakhirmu" });
    (async () => {
      try {
        const [aRes, cRes] = await Promise.all([
          applicationsApi.index().catch(() => ({ data: [] })),
          companiesApi.index().catch(() => ({ data: [] })),
        ]);
        setApps(aRes?.data ?? []);
        setCompanies(cRes?.data ?? []);
      } finally { setLoading(false); }
    })();
  }, [onMeta]);

  const diterima = apps.filter((a) => a.status === "accepted");
  const co = (id) => companies.find((c) => c.id === Number(id));

  const simpan = async () => {
    if (!dipilih) return;
    setSaving(true);
    try {
      await applicationsApi.update(dipilih, { is_final: true, status: "accepted" }).catch(() => {});
      setDone(true);
      setConfirm(false);
    } finally { setSaving(false); }
  };

  if (loading) return <p className="siswa-muted">Memuat…</p>;

  if (done) {
    return (
      <div className="siswa-card" style={{ textAlign: "center", padding: "48px 24px" }}>
        <span className="siswa-modal-ic green" style={{ margin: "0 auto 16px" }}>
          <i className="fa-solid fa-check"></i>
        </span>
        <h3 className="siswa-card-title" style={{ marginBottom: 8 }}>Pilihan Final Tersimpan!</h3>
        <p className="siswa-muted" style={{ marginBottom: 20 }}>
          Kamu memilih <b style={{ color: "#0f172a" }}>{co(apps.find((a) => a.id === dipilih)?.company_id)?.name}</b> sebagai
          tempat PKL. Sekolah akan memproses penempatanmu.
        </p>
        <button type="button" className="siswa-btn siswa-btn-primary" onClick={() => navigate("/siswa")}>
          Kembali ke Dashboard
        </button>
      </div>
    );
  }

  return (
    <div>
      <div className="siswa-banner amber">
        <span className="b-ic"><i className="fa-solid fa-triangle-exclamation"></i></span>
        <div>
          <strong>Pilih dengan hati-hati.</strong> Pilihan final hanya bisa ditentukan satu kali
          dan tidak bisa diubah setelah dikonfirmasi.
        </div>
      </div>

      {diterima.length === 0 ? (
        <div className="siswa-empty">
          <i className="fa-solid fa-hourglass-half"></i>
          Belum ada perusahaan yang menerima pengajuanmu.<br />Pilihan final bisa ditentukan setelah ada yang menerima.
        </div>
      ) : (
        <>
          <div className="siswa-grid2">
            {diterima.map((a) => {
              const c = co(a.company_id);
              const sel = dipilih === a.id;
              return (
                <div key={a.id} className={`siswa-pick ${sel ? "sel" : ""}`} onClick={() => setDipilih(a.id)}>
                  <div className="siswa-pick-top">
                    <span className="siswa-radio"></span>
                    {sel && <span className="siswa-badge s-badge-blue"><span className="dot"></span>Pilihanmu</span>}
                  </div>
                  <div className="siswa-co">
                    <span className="siswa-co-logo">{String(c?.name || "?").charAt(0)}</span>
                    <div className="siswa-co-tx">
                      <b style={{ fontSize: 16 }}>{c?.name || "—"}</b>
                      <small>{c?.industry || ""} • {c?.address || ""}</small>
                    </div>
                  </div>
                  {c?.description && <p className="siswa-muted" style={{ marginTop: 10, fontSize: 12.5 }}>{c.description}</p>}
                </div>
              );
            })}
          </div>
          <div style={{ textAlign: "center", marginTop: 20 }}>
            <button type="button" className="siswa-btn siswa-btn-primary" disabled={!dipilih}
              onClick={() => setConfirm(true)} style={{ padding: "13px 40px" }}>
              Konfirmasi Pilihan Final
            </button>
          </div>
        </>
      )}

      {confirm && (
        <div className="siswa-modal-bg" onClick={() => !saving && setConfirm(false)}>
          <div className="siswa-modal" onClick={(e) => e.stopPropagation()}>
            <span className="siswa-modal-ic blue"><i className="fa-solid fa-circle-question"></i></span>
            <h3>Yakin dengan pilihanmu?</h3>
            <p>
              Kamu memilih <b style={{ color: "#0f172a" }}>{co(apps.find((a) => a.id === dipilih)?.company_id)?.name}</b>.
              Pilihan ini tidak bisa diubah setelah dikonfirmasi.
            </p>
            <div className="siswa-modal-btns">
              <button type="button" className="siswa-btn siswa-btn-outline" disabled={saving} onClick={() => setConfirm(false)}>
                Batal
              </button>
              <button type="button" className="siswa-btn siswa-btn-primary" disabled={saving} onClick={simpan}>
                {saving ? "Menyimpan…" : "Ya, Konfirmasi"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default PilihPerusahaan;
