import { useEffect, useState } from "react";
import {
  companiesApi,
  placementsApi,
  usersApi,
  companyProposalsApi,
  companyMembershipsApi,
} from "../../api/index.js";
import { SkelCards } from "../../components/role/skeleton.jsx";
import "./admin-pages.css";

// Kapabilitas keanggotaan perusahaan (PRD bagian C) — sama dengan backend.
const CAPABILITIES = [
  { value: "company_admin", label: "Admin Perusahaan" },
  { value: "review_applications", label: "Meninjau Pengajuan" },
  { value: "verify_journals", label: "Verifikasi Jurnal" },
];

function MembershipForm({ companyId, accounts, busy, onSave }) {
  const [userId, setUserId] = useState("");
  const [capabilities, setCapabilities] = useState(["company_admin"]);
  const [error, setError] = useState("");

  const toggle = (cap) =>
    setCapabilities((prev) => (prev.includes(cap) ? prev.filter((x) => x !== cap) : [...prev, cap]));

  const submit = async (e) => {
    e.preventDefault();
    if (!userId) {
      setError("Pilih pengguna terlebih dahulu.");
      return;
    }
    setError("");
    await onSave(companyId, { user_id: Number(userId), capabilities });
    setUserId("");
  };

  return (
    <form className="zip-form" onSubmit={submit} style={{ marginTop: 12 }}>
      {error && <div className="zip-error">{error}</div>}
      <label className="zip-field">
        <span>Pengguna</span>
        <select value={userId} onChange={(e) => setUserId(e.target.value)}>
          <option value="">— Pilih akun (perusahaan/pembimbing/sekolah) —</option>
          {accounts.map((u) => (
            <option key={u.id} value={u.id}>{u.name} ({u.role})</option>
          ))}
        </select>
      </label>
      <div className="zip-field">
        <span>Kapabilitas</span>
        <div style={{ display: "flex", gap: 14, flexWrap: "wrap", paddingTop: 4 }}>
          {CAPABILITIES.map((cap) => (
            <label key={cap.value} style={{ display: "flex", alignItems: "center", gap: 6 }}>
              <input
                type="checkbox"
                checked={capabilities.includes(cap.value)}
                onChange={() => toggle(cap.value)}
              />
              {cap.label}
            </label>
          ))}
        </div>
      </div>
      <div className="zip-form-actions">
        <button type="submit" className="zip-btn zip-btn-primary" disabled={busy}>
          {busy ? "Menyimpan…" : "Beri Akses"}
        </button>
      </div>
    </form>
  );
}

function CompanyForm({ initial, onCancel, onSaved }) {
  const [form, setForm] = useState({
    name: initial?.name || "",
    // `industry` WAJIB dikirim saat membuat perusahaan (divalidasi backend).
    // `student_quota` TIDAK diisi admin — hanya perusahaan yang mengaturnya
    // lewat menu Profil Perusahaan. Saat tambah baru dikirim 0 sebagai default.
    industry: initial?.industry || "",
    is_active: initial ? !!initial.is_active : true,
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setError("");
    if (!form.name.trim()) {
      setError("Nama perusahaan wajib diisi.");
      return;
    }
    if (!String(form.industry).trim()) {
      setError("Bidang usaha wajib diisi.");
      return;
    }
    setSaving(true);
    try {
      const payload = {
        name: form.name.trim(),
        industry: String(form.industry).trim(),
        is_active: form.is_active,
      };
      if (initial?.id) {
        await companiesApi.update(initial.id, payload);
      } else {
        // Kuota awal 0; perusahaan mengisi sendiri kuota yang tersedia.
        await companiesApi.store({ ...payload, student_quota: 0 });
      }
      onSaved?.();
    } catch (err) {
      setError(err?.message || "Gagal menyimpan perusahaan.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <form className="zip-card zip-form" onSubmit={submit}>
      {error && <div className="zip-error">{error}</div>}
      <label className="zip-field">
        <span>Nama Perusahaan</span>
        <input value={form.name} onChange={set("name")} placeholder="cth PT Solusi Digital" />
      </label>
      <label className="zip-field">
        <span>Bidang Usaha</span>
        <input value={form.industry} onChange={set("industry")} placeholder="cth Teknologi Informasi" />
      </label>
      <div className="zip-info-box" style={{ marginBottom: 4 }}>
        <i className="fa-solid fa-circle-info"></i>
        <span>Kuota siswa diatur sendiri oleh perusahaan lewat menu Profil Perusahaan.</span>
      </div>
      <label className="zip-field">
        <span>Status</span>
        <select
          value={form.is_active ? "1" : "0"}
          onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === "1" }))}
        >
          <option value="1">Aktif</option>
          <option value="0">Nonaktif</option>
        </select>
      </label>
      <div className="zip-form-actions">
        <button type="button" className="zip-btn zip-btn-outline" onClick={onCancel}>Batal</button>
        <button type="submit" className="zip-btn zip-btn-primary" disabled={saving}>
          {saving ? "Menyimpan…" : "Simpan"}
        </button>
      </div>
    </form>
  );
}

function PerusahaanPartner({ onMeta }) {
  const [rows, setRows] = useState([]);
  const [placements, setPlacements] = useState([]);
  const [students, setStudents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [view, setView] = useState({ name: "list" });
  const [error, setError] = useState("");
  // Pengajuan perusahaan nonmitra + keanggotaan perusahaan (PRD bagian C & D).
  const [proposals, setProposals] = useState([]);
  const [memberships, setMemberships] = useState([]);
  const [accounts, setAccounts] = useState([]);
  const [busy, setBusy] = useState(false);
  const [rejecting, setRejecting] = useState(null);
  const [rejectReason, setRejectReason] = useState("");
  // Modal persetujuan: pilih akses (kapabilitas) untuk akun perusahaan baru.
  const [approving, setApproving] = useState(null);
  const [approveCaps, setApproveCaps] = useState([]);
  const [approveResult, setApproveResult] = useState(null);

  const load = async () => {
    setLoading(true);
    try {
      const [cRes, pRes, sRes, prRes, mRes, uRes] = await Promise.all([
        companiesApi.index().catch(() => ({ data: [] })),
        placementsApi.index().catch(() => ({ data: [] })),
        usersApi.index({ role: "student" }).catch(() => ({ data: [] })),
        companyProposalsApi.index().catch(() => ({ data: [] })),
        companyMembershipsApi.index().catch(() => ({ data: [] })),
        usersApi.index().catch(() => ({ data: [] })),
      ]);
      setRows(Array.isArray(cRes.data) ? cRes.data : []);
      setPlacements(Array.isArray(pRes.data) ? pRes.data : []);
      setStudents(Array.isArray(sRes.data) ? sRes.data : []);
      setProposals(Array.isArray(prRes.data) ? prRes.data : []);
      setMemberships(Array.isArray(mRes.data) ? mRes.data : []);
      setAccounts(Array.isArray(uRes.data) ? uRes.data : []);
    } catch (err) {
      setError(err?.message || "Gagal memuat perusahaan.");
    } finally {
      setLoading(false);
    }
  };

  const verifyProposal = async (company, action, reason, capabilities) => {
    setBusy(true);
    setError("");
    try {
      const res = await companyProposalsApi.verify(company.id, { action, reason, capabilities });
      setRejecting(null);
      setRejectReason("");
      setApproving(null);
      if (action === "approve") setApproveResult(res);
      await load();
    } catch (err) {
      setError(err?.message || "Gagal memverifikasi pengajuan perusahaan.");
    } finally {
      setBusy(false);
    }
  };

  const CAPABILITY_OPTIONS = [
    { value: "company_admin", label: "Admin Perusahaan" },
    { value: "review_applications", label: "Meninjau Pengajuan" },
    { value: "verify_journals", label: "Verifikasi Jurnal" },
  ];

  const toggleApproveCap = (v) => setApproveCaps((c) =>
    c.includes(v) ? c.filter((x) => x !== v) : [...c, v]
  );

  const openApprove = (company) => {
    setApproveResult(null);
    setApproveCaps(CAPABILITY_OPTIONS.map((o) => o.value));
    setApproving(company);
  };

  const saveMembership = async (companyId, payload) => {
    setBusy(true);
    setError("");
    try {
      await companyMembershipsApi.store({ company_id: companyId, ...payload });
      await load();
    } catch (err) {
      setError(err?.message || "Gagal menyimpan keanggotaan perusahaan.");
    } finally {
      setBusy(false);
    }
  };

  const setMembershipStatus = async (id, status) => {
    setBusy(true);
    setError("");
    try {
      await companyMembershipsApi.update(id, { status });
      await load();
    } catch (err) {
      setError(err?.message || "Gagal memperbarui keanggotaan perusahaan.");
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    onMeta?.({ title: "Perusahaan Partner", subtitle: `${rows.length} mitra • kuota • pembimbing` });
  }, [rows.length, onMeta]);

  const backToList = () => {
    setView({ name: "list" });
    load();
  };

  const doDelete = async () => {
    try {
      await companiesApi.destroy(view.row.id);
      backToList();
    } catch (err) {
      setError(err?.message || "Gagal menghapus perusahaan.");
    }
  };

  if (view.name === "tambah" || view.name === "edit") {
    return (
      <div className="zip-page">
        <CompanyForm
          initial={view.name === "edit" ? view.row : null}
          onCancel={backToList}
          onSaved={backToList}
        />
      </div>
    );
  }

  if (view.name === "hapus") {
    return (
      <div className="zip-page">
        <div className="zip-card zip-confirm">
          <h3>Hapus perusahaan {view.row.name}?</h3>
          <p>Data perusahaan dihapus dan tidak bisa dikembalikan. Lanjutkan?</p>
          <div className="zip-confirm-actions">
            <button type="button" className="zip-btn zip-btn-outline" onClick={backToList}>Batal</button>
            <button type="button" className="zip-btn zip-btn-danger" onClick={doDelete}>Ya, Hapus</button>
          </div>
        </div>
      </div>
    );
  }

  const studentById = Object.fromEntries(students.map((st) => [st.id, st]));
  const accountById = Object.fromEntries(accounts.map((u) => [u.id, u]));
  const terisiOf = (cid) => placements.filter((pl) => String(pl.company_id) === String(cid)).length;
  const pendingProposals = proposals.filter(
    (p) => p.verification_status === "pending_school_verification"
  );

  if (view.name === "detail") {
    const c = view.row;
    const terisi = terisiOf(c.id);
    const kuota = Number(c.student_quota) || 0;
    const pct = kuota > 0 ? Math.min(100, Math.round((terisi / kuota) * 100)) : 0;
    const siswaDiSini = placements.filter((pl) => String(pl.company_id) === String(c.id));
    return (
      <div className="zip-page">
        <div className="zip-toolbar">
          <button type="button" className="zip-btn-outline" onClick={backToList}>
            ← Kembali
          </button>
        </div>
        <div className="zip-card">
          <h3 className="zip-card-title">{c.name}</h3>
          <div className="zip-detail-grid">
            <div><span className="zip-label">Bidang</span><div>{c.industry || "-"}</div></div>
            <div><span className="zip-label">Status</span><div>{c.is_active ? "Aktif" : "Nonaktif"}</div></div>
            <div><span className="zip-label">Telepon</span><div>{c.phone || "-"}</div></div>
            <div><span className="zip-label">Email</span><div>{c.email || "-"}</div></div>
          </div>
          <div style={{ marginTop: 16 }}>
            <span className="zip-label">Kuota Terisi</span>
            <div className="zip-progress">
              <div className="zip-progress-bar" style={{ width: `${pct}%` }} />
            </div>
            <div className="zip-muted">{terisi}/{kuota} siswa ({pct}%)</div>
          </div>
        </div>
        <div className="zip-card">
          <h4 className="zip-card-title">Keanggotaan Perusahaan</h4>
          <div className="zip-muted" style={{ marginBottom: 8 }}>
            Keanggotaan aktif memberi kewenangan sisi industri beserta kapabilitasnya.
            Keanggotaan nonaktif tidak memberi akses apa pun.
          </div>
          <div className="zip-list">
            {memberships
              .filter((m) => String(m.company_id) === String(c.id))
              .map((m) => (
                <div className="zip-row" key={m.id}>
                  <span className="zip-row-text" style={{ flex: 1 }}>
                    <strong>{accountById[m.user_id]?.name || `Pengguna #${m.user_id}`}</strong>
                    <div className="zip-muted">
                      {m.status === "active" ? "Aktif" : "Nonaktif"} •{" "}
                      {(m.capabilities || []).join(", ") || "tanpa kapabilitas"}
                    </div>
                  </span>
                  <button
                    type="button"
                    className="zip-act zip-act-edit"
                    disabled={busy}
                    onClick={() => setMembershipStatus(m.id, m.status === "active" ? "inactive" : "active")}
                  >
                    {m.status === "active" ? "Nonaktifkan" : "Aktifkan"}
                  </button>
                </div>
              ))}
          </div>
          {memberships.filter((m) => String(m.company_id) === String(c.id)).length === 0 && (
            <div className="zip-muted">Belum ada keanggotaan untuk perusahaan ini.</div>
          )}
          <MembershipForm companyId={c.id} accounts={accounts} busy={busy} onSave={saveMembership} />
        </div>

        <div className="zip-card">
          <h4 className="zip-card-title">Siswa Diterima ({siswaDiSini.length})</h4>
          <div className="zip-list">
            {siswaDiSini.map((pl) => (
              <div className="zip-row" key={pl.id}>
                <span className="zip-row-text">
                  {studentById[pl.student_id]?.name || `Siswa #${pl.student_id}`}
                </span>
              </div>
            ))}
          </div>
          {siswaDiSini.length === 0 && <div className="zip-muted">Belum ada siswa.</div>}
        </div>
      </div>
    );
  }

  return (
    <div className="zip-page">
      <div className="zip-toolbar">
        <span className="zip-muted">{rows.length} mitra • kuota • pembimbing</span>
        <button
          type="button"
          className="zip-btn zip-btn-primary"
          onClick={() => {
            setView({ name: "tambah" });
            onMeta?.({ title: "Tambah Perusahaan", subtitle: "Nama • bidang • status" });
          }}
        >
          + Tambah Perusahaan
        </button>
      </div>
      {error && <div className="zip-error">{error}</div>}
      {pendingProposals.length > 0 && (
        <div className="zip-card">
          <h4 className="zip-card-title">
            Pengajuan Perusahaan Nonmitra ({pendingProposals.length})
          </h4>
          <div className="zip-list">
            {pendingProposals.map((p) => (
              <div className="zip-row" key={p.id} style={{ flexWrap: "wrap", gap: 8 }}>
                <span className="zip-row-text" style={{ flex: 1 }}>
                  <strong>{p.name}</strong> • {p.industry || "-"}
                  <div className="zip-muted">
                    {p.address || "-"} • {p.contact_person || "-"} • {p.phone || "-"} • {p.email || "-"}
                  </div>
                  <div className="zip-muted">
                    Menunggu verifikasi sekolah
                    {p.submitted_by ? ` • diajukan oleh pengguna #${p.submitted_by}` : ""}
                  </div>
                </span>
                <span style={{ display: "flex", gap: 8 }}>
                  <button
                    type="button"
                    className="zip-btn zip-btn-primary"
                    disabled={busy}
                    onClick={() => openApprove(p)}
                  >
                    Setujui
                  </button>
                  <button
                    type="button"
                    className="zip-btn zip-btn-outline"
                    disabled={busy}
                    onClick={() => {
                      setRejecting(p.id);
                      setRejectReason("");
                    }}
                  >
                    Tolak
                  </button>
                </span>
                {rejecting === p.id && (
                  <div style={{ flexBasis: "100%", display: "flex", gap: 8, marginTop: 8 }}>
                    <input
                      className="zip-field"
                      style={{ flex: 1 }}
                      placeholder="Alasan penolakan (wajib)"
                      value={rejectReason}
                      onChange={(e) => setRejectReason(e.target.value)}
                    />
                    <button
                      type="button"
                      className="zip-btn zip-btn-primary"
                      disabled={busy}
                      onClick={() => verifyProposal(p, "reject", rejectReason)}
                    >
                      Kirim Penolakan
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
      {approving && (
        <div className="zip-modal-backdrop" onClick={() => !busy && setApproving(null)}>
          <div className="zip-modal" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true">
            <h3>Setujui & Buatkan Akun</h3>
            <p className="zip-muted" style={{ fontSize: 13 }}>
              <strong>{approving.name}</strong> akan didaftarkan sebagai mitra dan otomatis
              dibuatkan akun perusahaan untuk <strong>{approving.contact_person || approving.email}</strong> ({approving.email}).
              Info login dikirim via WhatsApp & email.
            </p>
            <div className="zip-field" style={{ marginTop: 8 }}>
              <span>Beri akses</span>
              <div style={{ display: "flex", gap: 12, flexWrap: "wrap", marginTop: 4 }}>
                {CAPABILITY_OPTIONS.map((o) => (
                  <label key={o.value} style={{ display: "flex", gap: 6, alignItems: "center", fontSize: 13 }}>
                    <input
                      type="checkbox"
                      checked={approveCaps.includes(o.value)}
                      onChange={() => toggleApproveCap(o.value)}
                    />
                    {o.label}
                  </label>
                ))}
              </div>
            </div>
            <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
              <button
                type="button"
                className="zip-btn zip-btn-primary"
                disabled={busy}
                onClick={() => verifyProposal(approving, "approve", undefined, approveCaps)}
              >
                {busy ? "Memproses…" : "Setujui & Buatkan Akun"}
              </button>
              <button
                type="button"
                className="zip-btn zip-btn-outline"
                disabled={busy}
                onClick={() => setApproving(null)}
              >
                Batal
              </button>
            </div>
          </div>
        </div>
      )}
      {approveResult?.account && (
        <div className="zip-note" style={{ marginBottom: 14 }}>
          <strong><i className="fa-solid fa-circle-check"></i> Akun perusahaan dibuat: {approveResult.account.name} ({approveResult.account.email})</strong>
          <div className="zip-muted" style={{ fontSize: 13, marginTop: 4 }}>
            WhatsApp: {approveResult.delivery?.whatsapp?.status || "-"} • Email: {approveResult.delivery?.email?.status || "-"}
          </div>
        </div>
      )}
      {loading ? (
        <SkelCards n={3} />
      ) : (
        <div className="zip-list">
          {rows.map((c) => (
            <div className="zip-row" key={c.id}>
              <span className="zip-row-text" style={{ flex: 1 }}>
                <strong>{c.name}</strong> • {c.industry || "-"} • {c.is_active ? "Aktif" : "Nonaktif"}
                <span className="zip-progress" style={{ marginTop: 6, maxWidth: 220 }}>
                  <span className="zip-progress-bar" style={{ width: `${(Number(c.student_quota) || 0) > 0 ? Math.min(100, Math.round((terisiOf(c.id) / (Number(c.student_quota) || 1)) * 100)) : 0}%` }} />
                </span>
                <span className="zip-muted"> {terisiOf(c.id)}/{c.student_quota ?? 0} kuota</span>
              </span>
              <span className="zip-row-actions" style={{ display: "flex", gap: 8 }}>
                <button type="button" className="zip-act zip-act-detail" onClick={() => { setView({ name: "detail", row: c }); onMeta?.({ title: `Detail — ${c.name}`, subtitle: "Profil • kuota • siswa" }); }}>Detail</button>
                <button type="button" className="zip-act zip-act-edit" onClick={() => { setView({ name: "edit", row: c }); onMeta?.({ title: "Edit Perusahaan", subtitle: "Nama • bidang • status" }); }}>Edit</button>
                <button type="button" className="zip-act zip-act-hapus" onClick={() => { setView({ name: "hapus", row: c }); onMeta?.({ title: "Konfirmasi Hapus", subtitle: "Aksi berisiko • butuh konfirmasi" }); }}>Hapus</button>
              </span>
            </div>
          ))}
          {rows.length === 0 && <p className="zip-muted">Belum ada perusahaan mitra.</p>}
        </div>
      )}
    </div>
  );
}

export default PerusahaanPartner;
