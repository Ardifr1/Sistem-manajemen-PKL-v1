import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { companiesApi, applicationsApi, authApi, companyProposalsApi } from "../../api/index.js";
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
  // Pengajuan perusahaan nonmitra (PRD bagian D).
  const [proposals, setProposals] = useState([]);
  const [showForm, setShowForm] = useState(false);
  const [sendingProposal, setSendingProposal] = useState(false);
  const [form, setForm] = useState({
    name: "", industry: "", address: "", contact_person: "", phone: "", email: "", description: "",
  });
  const [proof, setProof] = useState(null);

  useEffect(() => {
    onMeta?.({ title: "Pilih Perusahaan", subtitle: "Tentukan tempat PKL impianmu" });
    (async () => {
      try {
        const [cRes, aRes, pRes] = await Promise.all([
          companiesApi.index({ is_partner: true }).catch(() => ({ data: [] })),
          applicationsApi.index().catch(() => ({ data: [] })),
          companyProposalsApi.index().catch(() => ({ data: [] })),
        ]);
        setCompanies(cRes?.data ?? []);
        setApps(aRes?.data ?? []);
        setProposals(pRes?.data ?? []);
      } finally { setLoading(false); }
    })();
  }, [onMeta]);

  const submitProposal = async (e) => {
    e.preventDefault();
    setSendingProposal(true);
    setMsg("");
    try {
      // FormData agar bukti opsional dapat dikirim bersama data perusahaan.
      const payload = new FormData();
      Object.entries(form).forEach(([key, value]) => payload.append(key, value ?? ""));
      if (proof) payload.append("proof", proof);

      await companyProposalsApi.store(payload);

      const pRes = await companyProposalsApi.index().catch(() => ({ data: [] }));
      setProposals(pRes?.data ?? []);
      setForm({ name: "", industry: "", address: "", contact_person: "", phone: "", email: "", description: "" });
      setProof(null);
      setShowForm(false);
      setMsg("Pengajuan perusahaan terkirim. Menunggu verifikasi Admin Sekolah.");
    } catch (err) {
      setMsg(err?.message || "Gagal mengirim pengajuan perusahaan.");
    } finally { setSendingProposal(false); }
  };

  const sudahDiajukan = (id) => apps.some((a) => Number(a.company_id) === Number(id));

  /**
   * Urutan pilihan pertama yang masih kosong (1-3).
   * Memakai apps.length akan mengirim 4 saat pengajuan lama sudah ditolak —
   * validasi backend menolak choice_order di luar 1..3.
   */
  const nextChoiceOrder = () => {
    const terpakai = new Set(
      apps.filter((a) => a.status !== "rejected").map((a) => Number(a.choice_order))
    );
    for (let i = 1; i <= 3; i += 1) if (!terpakai.has(i)) return i;
    return null;
  };

  const ajukan = async (c) => {
    const user = authApi.currentUser();
    const choiceOrder = nextChoiceOrder();
    if (choiceOrder === null) {
      setMsg("Maksimal 3 pilihan perusahaan untuk satu periode PKL.");
      return;
    }
    setSending(c.id);
    setMsg("");
    try {
      await applicationsApi.store({
        student_id: user?.id, company_id: c.id, choice_order: choiceOrder,
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

      {/* Ajukan perusahaan NONMITRA — menunggu verifikasi Admin Sekolah. */}
      <div className="siswa-card">
        <div className="siswa-between" style={{ marginBottom: 10 }}>
          <h3 className="siswa-card-title">Perusahaan Belum Terdaftar?</h3>
          <button type="button" className="siswa-btn siswa-btn-outline siswa-btn-sm"
            onClick={() => setShowForm((v) => !v)}>
            {showForm ? "Tutup" : "Ajukan Perusahaan"}
          </button>
        </div>
        <p className="siswa-muted" style={{ marginTop: 0, fontSize: 12.5 }}>
          Ajukan perusahaan nonmitra. Pengajuan diperiksa Admin Sekolah terlebih dahulu —
          setelah disetujui, perusahaan otomatis terdaftar sebagai mitra dan bisa dipilih seperti biasa.
        </p>

        {proposals.filter((p) => p.verification_status !== "verified").length > 0 && (
          <div style={{ marginBottom: 12 }}>
            {proposals.filter((p) => p.verification_status !== "verified").map((p) => (
              <div key={p.id} className="siswa-between" style={{ padding: "8px 0", borderBottom: "1px solid #f1f5f9" }}>
                <div>
                  <b style={{ fontSize: 13.5 }}>{p.name}</b>
                  <div className="siswa-muted" style={{ fontSize: 12 }}>
                    {p.industry}{p.verification_note ? ` • ${p.verification_note}` : " • Menunggu verifikasi sekolah"}
                  </div>
                </div>
                <span className={`siswa-badge ${p.verification_status === "rejected" ? "s-badge-red" : "s-badge-amber"}`}>
                  <span className="dot"></span>
                  {p.verification_status === "rejected" ? "Ditolak" : "Menunggu Verifikasi"}
                </span>
              </div>
            ))}
          </div>
        )}

        {showForm && (
          <form onSubmit={submitProposal}>
            <div className="siswa-grid2">
              <input className="siswa-input" placeholder="Nama perusahaan" required
                value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
              <input className="siswa-input" placeholder="Bidang usaha" required
                value={form.industry} onChange={(e) => setForm({ ...form, industry: e.target.value })} />
              <input className="siswa-input" placeholder="Nama kontak" required
                value={form.contact_person} onChange={(e) => setForm({ ...form, contact_person: e.target.value })} />
              <input className="siswa-input" placeholder="No. telepon" required
                value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
              <input className="siswa-input" type="email" placeholder="Email perusahaan" required
                value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
              <input className="siswa-input" placeholder="Alamat perusahaan" required
                value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
            </div>
            <textarea className="siswa-input" rows={3} placeholder="Keterangan tambahan (opsional)"
              style={{ marginTop: 10, width: "100%" }}
              value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            <div style={{ marginTop: 10 }}>
              <span className="siswa-muted" style={{ fontSize: 12.5 }}>
                Bukti pendukung (opsional, PDF/JPG/PNG maks 5MB)
              </span>
              <input type="file" accept=".pdf,.jpg,.jpeg,.png" style={{ display: "block", marginTop: 6 }}
                onChange={(e) => setProof(e.target.files?.[0] ?? null)} />
            </div>
            <button type="submit" className="siswa-btn siswa-btn-primary" style={{ marginTop: 14 }}
              disabled={sendingProposal}>
              {sendingProposal ? "Mengirim…" : "Kirim Pengajuan"}
            </button>
          </form>
        )}
      </div>

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
  const [err, setErr] = useState("");

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
    setErr("");
    try {
      // Backend otomatis membuat penempatan resmi + menutup tawaran lain
      // pada periode yang sama sehingga siswa benar-benar hanya berada di
      // satu perusahaan (bukan hanya di UI).
      const res = await applicationsApi.choose(dipilih);
      setDone(res?.placement || true);
      setConfirm(false);
    } catch (e) {
      setErr(e?.message || "Gagal menyimpan pilihan final.");
      setConfirm(false);
    } finally { setSaving(false); }
  };

  if (loading) return <p className="siswa-muted">Memuat…</p>;

  if (done) {
    const placement = done === true ? null : done;
    const scheduled = placement?.status === "scheduled";
    const startDate = placement?.start_date
      ? new Date(placement.start_date).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" })
      : null;
    return (
      <div className="siswa-card" style={{ textAlign: "center", padding: "48px 24px" }}>
        <span className="siswa-modal-ic green" style={{ margin: "0 auto 16px" }}>
          <i className="fa-solid fa-check"></i>
        </span>
        <h3 className="siswa-card-title" style={{ marginBottom: 8 }}>Penempatan Resmi Dibuat!</h3>
        <p className="siswa-muted" style={{ marginBottom: 20 }}>
          Kamu memilih <b style={{ color: "#0f172a" }}>{co(apps.find((a) => a.id === dipilih)?.company_id)?.name}</b> sebagai
          tempat PKL. {scheduled && startDate
            ? <>Penempatanmu resmi tercatat dan PKL akan aktif pada <b style={{ color: "#0f172a" }}>{startDate}</b>.</>
            : "PKL kamu sudah aktif."}
        </p>
        <button type="button" className="siswa-btn siswa-btn-primary" onClick={() => navigate("/siswa")}>
          Kembali ke Dashboard
        </button>
      </div>
    );
  }

  return (
    <div>
      {err && <div className="siswa-banner amber" style={{ marginBottom: 14 }}>{err}</div>}
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
