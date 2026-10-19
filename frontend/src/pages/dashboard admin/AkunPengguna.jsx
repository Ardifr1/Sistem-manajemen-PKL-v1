import { useEffect, useState } from "react";
import { usersApi, journalsApi, applicationsApi, placementsApi, profilesApi, companiesApi, companyMembershipsApi, ROLE_LABEL } from "../../api/index.js";
import { UserCell, RoleBadge, StatusBadge, initials, avatarColor } from "../../components/admin/user-table.jsx";
import { SkelCards } from "../../components/role/skeleton.jsx";
import "./admin-pages.css";

const BULAN = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
const fmtShort = (iso) => {
  if (!iso) return "-";
  const s = String(iso).slice(0, 10);
  const [y, m, d] = s.split("-").map(Number);
  if (!y || !m || !d || !BULAN[m - 1]) return s;
  return `${d} ${BULAN[m - 1]}`;
};

/**
 * Detail Pengguna ala referensi: header profil + Data Diri + Riwayat Akun.
 */
function DetailPengguna({ row, onBack, onEdit, onHapus, onMeta }) {
  const [profil, setProfil] = useState({});
  const [placement, setPlacement] = useState(null);
  const [companyName, setCompanyName] = useState("");
  const [teacherName, setTeacherName] = useState("");
  // Status pengiriman ulang informasi akun (tautan aktivasi).
  const [resending, setResending] = useState(false);
  const [resendResult, setResendResult] = useState(null);

  useEffect(() => {
    onMeta?.({ title: "Detail Pengguna", subtitle: row.name });
    (async () => {
      try {
        const [profRes, plRes, cRes, uRes] = await Promise.all([
          profilesApi.index().catch(() => ({ data: [] })),
          placementsApi.index().catch(() => ({ data: [] })),
          companiesApi.index().catch(() => ({ data: [] })),
          usersApi.index().catch(() => ({ data: [] })),
        ]);
        const profiles = Array.isArray(profRes.data) ? profRes.data : [];
        const placements = Array.isArray(plRes.data) ? plRes.data : [];
        const companies = Array.isArray(cRes.data) ? cRes.data : [];
        const users = Array.isArray(uRes.data) ? uRes.data : [];
        setProfil(profiles.find((p) => p.user_id === row.id) || {});
        const pl = placements.find((p) => p.student_id === row.id);
        setPlacement(pl || null);
        if (pl) {
          setCompanyName(companies.find((c) => c.id === pl.company_id)?.name || "");
          const t = users.find((u) => u.id === pl.teacher_id);
          setTeacherName(t?.name || "");
        }
      } catch (e) { /* tampilkan seadanya */ }
    })();
  }, [row.id, onMeta]);

  const sub = [profil.nis ? `NIS ${profil.nis}` : null, profil.kelas || profil.class, row.email]
    .filter(Boolean).join(" • ");

  const riwayat = [
    {
      icon: "fa-user",
      bg: "#dbeafe",
      color: "#1d4ed8",
      title: "Akun dibuat",
      desc: "Didaftarkan oleh admin via menu Pengguna",
      date: row.created_at ? fmtShort(row.created_at) : "-",
    },
  ];
  if (profil.cv_path || profil.portfolio_path || profil.certificate_path) {
    riwayat.push({
      icon: "fa-file-lines",
      bg: "#dcfce7",
      color: "#15803d",
      title: "Dokumen dilengkapi",
      desc: "CV, portofolio & sertifikat terverifikasi",
      date: profil.updated_at ? fmtShort(profil.updated_at) : "-",
    });
  }
  if (placement) {
    riwayat.push({
      icon: "fa-briefcase",
      bg: "#fef3c7",
      color: "#b45309",
      title: "Penempatan resmi",
      desc: `${companyName} • disetujui sekolah`,
      date: placement.updated_at ? fmtShort(placement.updated_at) : "-",
    });
  }

  return (
    <div>
      <button type="button" className="zip-back" onClick={onBack}>‹ Kembali ke daftar pengguna</button>

      <div className="zip-card" style={{ maxWidth: "none", marginBottom: 16 }}>
        <div style={{ display: "flex", alignItems: "center", gap: 18, flexWrap: "wrap" }}>
          <span className="zip-avatar" style={{ background: avatarColor(row.name), width: 64, height: 64, fontSize: 20 }}>
            {initials(row.name)}
          </span>
          <div style={{ flex: 1, minWidth: 200 }}>
            <div style={{ fontSize: 19, fontWeight: 800, color: "#0f172a" }}>{row.name}</div>
            <div className="zip-sub" style={{ margin: "4px 0 8px" }}>{sub}</div>
            <div style={{ display: "flex", gap: 8 }}>
              <RoleBadge role={row.role} />
              <StatusBadge active={row.is_active} />
            </div>
          </div>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            <button
              type="button"
              className="zip-act zip-act-edit"
              disabled={resending}
              onClick={async () => {
                setResending(true);
                setResendResult(null);
                try {
                  const res = await usersApi.resendAccount(row.id);
                  setResendResult(res?.data?.delivery || { error: "Status pengiriman tidak tersedia." });
                } catch (err) {
                  setResendResult({ error: err?.message || "Gagal mengirim ulang informasi akun." });
                } finally {
                  setResending(false);
                }
              }}
            >
              <i className="fa-solid fa-paper-plane" style={{ marginRight: 6 }}></i>
              {resending ? "Mengirim…" : "Kirim ulang info akun"}
            </button>
            <button type="button" className="zip-act zip-act-edit" onClick={onEdit}>
              <i className="fa-solid fa-pen" style={{ marginRight: 6 }}></i>Edit
            </button>
            <button type="button" className="zip-act zip-act-hapus" onClick={onHapus}>
              <i className="fa-solid fa-trash" style={{ marginRight: 6 }}></i>Hapus
            </button>
          </div>
        </div>
      </div>

      {resendResult && (
        <div className="zip-delivery" role="status" aria-live="polite">
          <strong>Status pengiriman ulang</strong>
          {resendResult.error ? (
            <div className="zip-delivery-row is-failed">
              <span className="zip-delivery-msg">{resendResult.error}</span>
            </div>
          ) : (
            DELIVERY_CHANNELS.map(([key, label]) => {
              const item = resendResult[key];
              if (!item) return null;
              const state = item.status || "failed";
              return (
                <div key={key} className="zip-delivery-row">
                  <span className="zip-delivery-channel">{label}</span>
                  <span className={`zip-delivery-state is-${state}`}>
                    <i
                      className={`fa-solid ${
                        state === "sent"
                          ? "fa-circle-check"
                          : state === "not_configured"
                            ? "fa-triangle-exclamation"
                            : "fa-circle-xmark"
                      }`}
                    ></i>
                    {DELIVERY_LABEL[state] || state}
                  </span>
                  <span className="zip-delivery-msg">{item.message}</span>
                </div>
              );
            })
          )}
        </div>
      )}

      <div className="zip-cols2">
        <div className="zip-card">
          <h3 className="zip-card-title" style={{ marginTop: 0 }}>Data Diri</h3>
          <div className="zip-kv"><span>Nama lengkap</span><strong>{row.name}</strong></div>
          <div className="zip-kv"><span>NIS / NISN</span><strong>{profil.nis || profil.nisn || "-"}</strong></div>
          <div className="zip-kv"><span>Kelas</span><strong>{profil.kelas || profil.class || "-"}</strong></div>
          <div className="zip-kv"><span>Jurusan</span><strong>{profil.jurusan || profil.major || "-"}</strong></div>
          <div className="zip-kv"><span>Email</span><strong>{row.email}</strong></div>
          <div className="zip-kv"><span>No. HP</span><strong>{row.phone || profil.phone || profil.no_hp || "-"}</strong></div>
          {row.role === "student" && (
            <div className="zip-kv"><span>Guru pembimbing</span><strong>{teacherName || "-"}</strong></div>
          )}
        </div>

        <div className="zip-card">
          <h3 className="zip-card-title" style={{ marginTop: 0 }}>Riwayat Akun</h3>
          <div className="zip-timeline">
            {riwayat.map((r, i) => (
              <div className="zip-tl-item" key={i}>
                <span className="zip-tl-icon" style={{ background: r.bg, color: r.color }}>
                  <i className={`fa-solid ${r.icon}`}></i>
                </span>
                <div style={{ flex: 1 }}>
                  <div style={{ fontWeight: 700, fontSize: 13.5 }}>{r.title}</div>
                  <div className="zip-sub">{r.desc}</div>
                </div>
                <span className="zip-sub">{r.date}</span>
              </div>
            ))}
          </div>
        </div>
      </div>

      <KeanggotaanPerusahaan userId={row.id} userRole={row.role} />
    </div>
  );
}

/**
 * Kelola keanggotaan perusahaan eksplisit untuk satu pengguna (khusus admin).
 * Peran utama pengguna TIDAK berubah — keanggotaan hanya membuka fungsi
 * perusahaan sesuai kapabilitas yang dipilih.
 */
function KeanggotaanPerusahaan({ userId, userRole }) {
  const [rows, setRows] = useState([]);
  const [companies, setCompanies] = useState([]);
  const [loading, setLoading] = useState(true);
  const [companyId, setCompanyId] = useState("");
  const [caps, setCaps] = useState([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const load = async () => {
    setLoading(true);
    try {
      const [mRes, cRes] = await Promise.all([
        companyMembershipsApi.index({ user_id: userId }).catch(() => ({ data: [] })),
        companiesApi.index().catch(() => ({ data: [] })),
      ]);
      setRows(Array.isArray(mRes.data) ? mRes.data : []);
      setCompanies(Array.isArray(cRes.data) ? cRes.data : []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, [userId]);

  const toggleCap = (v) => setCaps((c) => c.includes(v) ? c.filter((x) => x !== v) : [...c, v]);

  const grant = async (e) => {
    e.preventDefault();
    setError("");
    if (!companyId) { setError("Pilih perusahaan terlebih dahulu."); return; }
    setSaving(true);
    try {
      await companyMembershipsApi.store({ user_id: userId, company_id: Number(companyId), capabilities: caps });
      setCompanyId("");
      setCaps([]);
      await load();
    } catch (err) {
      setError(err?.message || "Gagal menambahkan keanggotaan.");
    } finally {
      setSaving(false);
    }
  };

  const toggleActive = async (m) => {
    try {
      await companyMembershipsApi.update(m.id, { status: m.status === "active" ? "inactive" : "active" });
      await load();
    } catch (err) {
      setError(err?.message || "Gagal mengubah status.");
    }
  };

  const revoke = async (m) => {
    if (!window.confirm(`Cabut keanggotaan di ${m.company?.name || "perusahaan ini"}?`)) return;
    try {
      await companyMembershipsApi.destroy(m.id);
      await load();
    } catch (err) {
      setError(err?.message || "Gagal mencabut keanggotaan.");
    }
  };

  const capLabel = (v) => (companyMembershipsApi.CAPABILITIES || []).find((c) => c.value === v)?.label || v;

  return (
    <div className="zip-card" style={{ maxWidth: "none", marginTop: 16 }}>
      <h3 className="zip-card-title" style={{ marginTop: 0 }}>
        <i className="fa-solid fa-building" style={{ marginRight: 8 }}></i>
        Keanggotaan Perusahaan
      </h3>
      <div className="zip-info-box" style={{ marginBottom: 12 }}>
        <i className="fa-solid fa-circle-info"></i>
        <span>
          Peran utama tetap <strong>{roleLabel(userRole)}</strong>. Keanggotaan bersifat eksplisit:
          tindakan sekolah terhadap perusahaan tersebut otomatis diblokir (hard block konflik kepentingan).
        </span>
      </div>
      {error && <div className="zip-error">{error}</div>}
      {loading ? (
        <div className="zip-sub">Memuat…</div>
      ) : rows.length === 0 ? (
        <div className="zip-sub" style={{ marginBottom: 12 }}>Belum ada keanggotaan perusahaan.</div>
      ) : (
        <div style={{ marginBottom: 12 }}>
          {rows.map((m) => (
            <div key={m.id} className="zip-kv" style={{ alignItems: "center" }}>
              <span>
                <strong>{m.company?.name || `Perusahaan #${m.company_id}`}</strong>
                <br />
                <span className="zip-sub">{(m.capabilities || []).map(capLabel).join(", ") || "—"}</span>
              </span>
              <strong style={{ display: "flex", gap: 8, alignItems: "center" }}>
                <StatusBadge active={m.status === "active"} />
                <button type="button" className="zip-act zip-act-edit" onClick={() => toggleActive(m)}>
                  {m.status === "active" ? "Nonaktifkan" : "Aktifkan"}
                </button>
                <button type="button" className="zip-act zip-act-hapus" onClick={() => revoke(m)}>Cabut</button>
              </strong>
            </div>
          ))}
        </div>
      )}
      <form onSubmit={grant}>
        <div className="zip-cols2" style={{ gridTemplateColumns: "1fr 1fr" }}>
          <label className="zip-field">
            <span>Perusahaan</span>
            <select value={companyId} onChange={(e) => setCompanyId(e.target.value)}>
              <option value="">— Pilih perusahaan —</option>
              {companies.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </label>
          <div className="zip-field">
            <span>Kapabilitas</span>
            <div style={{ display: "flex", gap: 12, flexWrap: "wrap", paddingTop: 6 }}>
              {(companyMembershipsApi.CAPABILITIES || []).map((c) => (
                <label key={c.value} style={{ display: "flex", gap: 6, alignItems: "center", fontSize: 13 }}>
                  <input type="checkbox" checked={caps.includes(c.value)} onChange={() => toggleCap(c.value)} />
                  {c.label}
                </label>
              ))}
            </div>
          </div>
        </div>
        <div className="zip-form-actions">
          <button type="submit" className="zip-btn-primary" disabled={saving}>
            {saving ? "Menyimpan…" : "Tambah Keanggotaan"}
          </button>
        </div>
      </form>
    </div>
  );
}

/**
 * Konfirmasi hapus pengguna ala referensi: banner permanen + data terkait + checkbox.
 */
function HapusPengguna({ row, onCancel, onDeleted, onMeta }) {
  const [counts, setCounts] = useState({ jurnal: 0, pengajuan: 0, dokumen: 0, penempatan: 0 });
  const [profil, setProfil] = useState({});
  const [checked, setChecked] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    onMeta?.({ title: "Hapus Pengguna", subtitle: "Konfirmasi penghapusan akun secara permanen" });
    (async () => {
      try {
        const [jRes, aRes, pRes, profRes] = await Promise.all([
          journalsApi.index().catch(() => ({ data: [] })),
          applicationsApi.index().catch(() => ({ data: [] })),
          placementsApi.index().catch(() => ({ data: [] })),
          profilesApi.index().catch(() => ({ data: [] })),
        ]);
        const journals = Array.isArray(jRes.data) ? jRes.data : [];
        const apps = Array.isArray(aRes.data) ? aRes.data : [];
        const placements = Array.isArray(pRes.data) ? pRes.data : [];
        const profiles = Array.isArray(profRes.data) ? profRes.data : [];
        const prof = profiles.find((p) => p.user_id === row.id) || {};
        setProfil(prof);
        const myPlacements = placements.filter((p) => p.student_id === row.id);
        const myPlacementIds = new Set(myPlacements.map((p) => p.id));
        const dokumen = [prof.cv_path, prof.portfolio_path, prof.certificate_path].filter(Boolean).length;
        setCounts({
          jurnal: journals.filter((j) => myPlacementIds.has(j.placement_id)).length,
          pengajuan: apps.filter((a) => a.student_id === row.id).length,
          dokumen,
          penempatan: myPlacements.length,
        });
      } catch (e) { /* counts tetap 0 */ }
    })();
  }, [row.id, onMeta]);

  const doDelete = async () => {
    if (!checked) return;
    setDeleting(true);
    setError("");
    try {
      await usersApi.destroy(row.id);
      onDeleted();
    } catch (err) {
      setError(err?.message || "Gagal menghapus pengguna.");
      setDeleting(false);
    }
  };

  const sub = [ROLE_LABEL?.[row.role] || row.role, profil.kelas || profil.class, profil.nis ? `NIS ${profil.nis}` : null]
    .filter(Boolean).join(" • ");

  return (
    <div style={{ maxWidth: 560, margin: "0 auto" }}>
      <div className="zip-card">
        <div className="zip-danger-banner">
          <span className="zip-danger-icon"><i className="fa-solid fa-triangle-exclamation"></i></span>
          <div><strong>Tindakan permanen.</strong> Akun dan seluruh data terkait akan dihapus dan tidak dapat dikembalikan.</div>
        </div>

        <div className="zip-user-card">
          <span className="zip-avatar" style={{ background: avatarColor(row.name), width: 44, height: 44 }}>{initials(row.name)}</span>
          <span>
            <div className="zip-user-name">{row.name}</div>
            <div className="zip-user-email">{sub}</div>
          </span>
        </div>

        <div style={{ fontWeight: 700, fontSize: 13.5, margin: "14px 0 8px" }}>Data terkait yang ikut terhapus:</div>
        <div className="zip-kv"><span>Jurnal PKL</span><strong>{counts.jurnal} entri</strong></div>
        <div className="zip-kv"><span>Pengajuan perusahaan</span><strong>{counts.pengajuan} pengajuan</strong></div>
        <div className="zip-kv"><span>Dokumen (CV, portofolio, sertifikat)</span><strong>{counts.dokumen} berkas</strong></div>
        <div className="zip-kv"><span>Penempatan resmi</span><strong>{counts.penempatan} penempatan</strong></div>

        <label className="zip-check" style={{ marginTop: 16 }}>
          <input type="checkbox" checked={checked} onChange={(e) => setChecked(e.target.checked)} />
          <span style={{ fontSize: 12.5, color: "#64748b" }}>Saya memahami bahwa penghapusan ini bersifat permanen dan tidak dapat dibatalkan.</span>
        </label>

        {error && <div className="zip-error">{error}</div>}

        <div className="zip-form-actions" style={{ marginTop: 8 }}>
          <button type="button" className="zip-btn-outline" onClick={onCancel}>Batal</button>
          <button
            type="button"
            className="zip-btn-danger"
            disabled={!checked || deleting}
            onClick={doDelete}
            style={{ opacity: checked ? 1 : 0.5 }}
          >
            <i className="fa-solid fa-trash" style={{ marginRight: 6 }}></i>
            {deleting ? "Menghapus…" : "Hapus Permanen"}
          </button>
        </div>
      </div>
    </div>
  );
}

const ROLE_OPTIONS = ["admin", "teacher", "student", "company"];

/** Kanal pengiriman informasi akun yang dilaporkan backend. */
const DELIVERY_CHANNELS = [
  ["email", "Email"],
  ["whatsapp", "WhatsApp"],
];

const DELIVERY_LABEL = {
  sent: "Terkirim",
  failed: "Gagal",
  not_configured: "Belum dikonfigurasi",
};

function roleLabel(role) {
  return ROLE_LABEL?.[role] || role;
}

function UserForm({ initial, onCancel, onSaved, onMeta }) {
  const isEdit = !!initial?.id;
  const [form, setForm] = useState({
    name: initial?.name || "",
    email: initial?.email || "",
    phone: initial?.phone || "",
    password: "",
    role: initial?.role || "student",
    nis: initial?.student_profile?.nis || "",
    nip: "",
    is_active: initial ? !!initial.is_active : true,
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  // Status pengiriman informasi akun (email + WhatsApp) untuk akun baru.
  const [delivery, setDelivery] = useState(null);

  useEffect(() => {
    onMeta?.({
      title: isEdit ? "Edit Pengguna" : "Tambah Pengguna Baru",
      subtitle: isEdit ? `Ubah data ${initial.name}` : "Daftarkan akun baru ke dalam sistem",
    });
  }, [isEdit, onMeta]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setError("");
    if (!form.name.trim() || !form.email.trim()) {
      setError("Nama dan email wajib diisi.");
      return;
    }
    // Terima spasi/strip seperti "0812-3456-7890"; backend menormalkan ke 628….
    const phoneDigits = form.phone.trim().replace(/[\s\-().]/g, "");
    if (phoneDigits && !/^(\+62|62|0)8[0-9]{7,12}$/.test(phoneDigits)) {
      setError("Format nomor HP tidak valid (contoh: 081234567890).");
      return;
    }
    if (form.password && form.password.length < 8) {
      setError("Password minimal 8 karakter.");
      return;
    }
    setSaving(true);
    try {
      // Username dibuat otomatis oleh backend — tidak dikirim dari form.
      const payload = {
        name: form.name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim().replace(/[\s\-().]/g, "") || null,
        role: form.role,
        is_active: form.is_active,
      };
      if (form.role === "student" && form.nis.trim()) payload.nis = form.nis.trim();
      if (form.role === "teacher" && form.nip.trim()) payload.nip = form.nip.trim();
      if (form.password) payload.password = form.password;

      if (isEdit) {
        await usersApi.update(initial.id, payload);
        onSaved?.();
      } else {
        const res = await usersApi.store(payload);
        // Akun tanpa password manual: backend mengirim tautan aktivasi via
        // email + WhatsApp dan mengembalikan status tiap kanal.
        if (res?.delivery) setDelivery(res.delivery);
        else onSaved?.();
      }
    } catch (err) {
      setError(err?.message || "Gagal menyimpan pengguna.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div>
      <button type="button" className="zip-back" onClick={onCancel}>‹ Kembali ke daftar pengguna</button>
      <div className="zip-cols2" style={{ gridTemplateColumns: "1.4fr 1fr", alignItems: "start" }}>
        <form className="zip-card" onSubmit={submit} style={{ maxWidth: "none" }}>
          <h3 className="zip-card-title" style={{ marginTop: 0 }}>Informasi Akun</h3>
          {error && <div className="zip-error">{error}</div>}
          {delivery && (
            <div className="zip-delivery" role="status" aria-live="polite">
              <strong>Status pengiriman informasi akun</strong>
              {DELIVERY_CHANNELS.map(([key, label]) => {
                const item = delivery[key];
                if (!item) return null;
                const state = item.status || "failed";
                return (
                  <div key={key} className={`zip-delivery-row is-${state}`}>
                    <span className="zip-delivery-channel">{label}</span>
                    <span className={`zip-delivery-state is-${state}`}>
                      <i
                        className={`fa-solid ${
                          state === "sent"
                            ? "fa-circle-check"
                            : state === "not_configured"
                              ? "fa-triangle-exclamation"
                              : "fa-circle-xmark"
                        }`}
                      ></i>
                      {DELIVERY_LABEL[state] || state}
                    </span>
                    <span className="zip-delivery-msg">{item.message}</span>
                  </div>
                );
              })}
              <button
                type="button"
                className="zip-btn-primary"
                style={{ marginTop: 10 }}
                onClick={onSaved}
              >
                Selesai
              </button>
            </div>
          )}
          <label className="zip-field">
            <span>Nama Lengkap</span>
            <input value={form.name} onChange={set("name")} placeholder="cth Dewi Lestari" />
          </label>
          <label className="zip-field">
            <span>Email</span>
            <input type="email" value={form.email} onChange={set("email")} placeholder="cth dewi@smk.sch.id" />
          </label>
          <label className="zip-field">
            <span>Nomor HP (WhatsApp)</span>
            <input
              type="tel"
              inputMode="tel"
              value={form.phone}
              onChange={set("phone")}
              placeholder="cth 081234567890"
            />
            <small className="zip-hint">
              Dipakai untuk mengirim tautan aktivasi via WhatsApp. Format: 08… / +62….
            </small>
          </label>
          <label className="zip-field">
            <span>Peran</span>
            <select value={form.role} onChange={set("role")}>
              <option value="" disabled>Pilih peran</option>
              {ROLE_OPTIONS.map((r) => (
                <option key={r} value={r}>{roleLabel(r)}</option>
              ))}
            </select>
          </label>
          {form.role === "student" && (
            <label className="zip-field">
              <span>NIS</span>
              <input
                value={form.nis}
                onChange={set("nis")}
                placeholder="cth 12345678"
              />
            </label>
          )}
          {form.role === "teacher" && (
            <label className="zip-field">
              <span>NIP</span>
              <input
                value={form.nip}
                onChange={set("nip")}
                placeholder="cth 198501012010011001"
              />
            </label>
          )}
          <label className="zip-field">
            <span>Password{isEdit ? " (kosongkan jika tidak diubah)" : " (opsional)"}</span>
            <input
              type="password"
              value={form.password}
              onChange={set("password")}
              placeholder="Minimal 8 karakter"
              autoComplete="new-password"
            />
            <small className="zip-hint">
              {isEdit
                ? "Kosongkan bila tidak ingin mengubah password."
                : "Kosongkan agar sistem mengirim tautan aktivasi via email & WhatsApp. Password tidak pernah dikirim sebagai teks biasa."}
            </small>
          </label>
          <div className="zip-field">
            <span>Status Akun</span>
            <label className="zip-toggle">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
              />
              <span className="zip-toggle-track"><span className="zip-toggle-thumb" /></span>
              <span style={{ fontWeight: 600 }}>{form.is_active ? "Aktif" : "Nonaktif"}</span>
            </label>
          </div>
          <div className="zip-form-actions">
            <button type="button" className="zip-btn-outline" onClick={onCancel}>Batal</button>
            <button type="submit" className="zip-btn-primary" disabled={saving}>
              {saving ? "Menyimpan…" : isEdit ? "Simpan Perubahan" : "Simpan Pengguna"}
            </button>
          </div>
        </form>

        <div className="zip-card">
          <h3 className="zip-card-title" style={{ marginTop: 0 }}>Panduan</h3>
          <div className="zip-info-box">
            <i className="fa-solid fa-circle-info"></i>
            <span>Peran menentukan hak akses pengguna di dalam sistem SiMagang.</span>
          </div>
          <div className="zip-guide-list">
            <div><strong>Admin</strong><span>Akses penuh ke seluruh modul & pengaturan sistem.</span></div>
            <div><strong>Guru</strong><span>Memantau siswa bimbingan, jurnal, dan penilaian.</span></div>
            <div><strong>Siswa</strong><span>Mengisi jurnal, absensi, dan melihat perkembangan PKL.</span></div>
            <div><strong>Industri</strong><span>Memverifikasi jurnal & memberi evaluasi penempatan.</span></div>
          </div>
        </div>
      </div>
    </div>
  );
}

function Pengguna({ onMeta }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [view, setView] = useState({ name: "list" }); // list | tambah | edit | hapus
  const [error, setError] = useState("");
  const [resetReqs, setResetReqs] = useState([]);

  const load = () => {
    setLoading(true);
    Promise.all([
      usersApi.index().catch((err) => { throw err; }),
      usersApi.resetRequests().catch(() => ({ data: [] })),
    ])
      .then(([res, rr]) => {
        const all = Array.isArray(res.data) ? res.data : [];
        // akun pembimbing industri dikelola perusahaan, jangan tampil di sini
        setRows(all.filter((u) => u.role !== "supervisor"));
        setResetReqs(Array.isArray(rr.data) ? rr.data : []);
      })
      .catch((err) => setError(err?.message || "Gagal memuat pengguna."))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
    onMeta?.({ title: "Pengguna", subtitle: "Kelola akun • Tambah/Edit/Detail/Hapus" });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const backToList = () => {
    setView({ name: "list" });
    onMeta?.({ title: "Pengguna", subtitle: "Kelola akun • Tambah/Edit/Detail/Hapus" });
    load();
  };

  const doDelete = async () => {
    try {
      await usersApi.destroy(view.row.id);
      backToList();
    } catch (err) {
      setError(err?.message || "Gagal menghapus pengguna.");
    }
  };

  if (view.name === "tambah") {
    return (
      <div className="zip-page has-m-cards">
        <UserForm onCancel={backToList} onSaved={backToList} onMeta={onMeta} />
      </div>
    );
  }

  if (view.name === "detail") {
    return (
      <div className="zip-page">
        <DetailPengguna
          row={view.row}
          onBack={backToList}
          onEdit={() => setView({ name: "edit", row: view.row })}
          onHapus={() => setView({ name: "hapus", row: view.row })}
          onMeta={onMeta}
        />
      </div>
    );
  }

  if (view.name === "edit") {
    const handleSaved = async () => {
      if (view.resetRequestId) {
        try { await usersApi.resolveResetRequest(view.resetRequestId); } catch { /* abaikan */ }
      }
      backToList();
    };
    return (
      <div className="zip-page">
        <UserForm initial={view.row} onCancel={backToList} onSaved={handleSaved} onMeta={onMeta} />
      </div>
    );
  }

  if (view.name === "hapus") {
    return (
      <div className="zip-page">
        <HapusPengguna row={view.row} onCancel={backToList} onDeleted={backToList} onMeta={onMeta} />
      </div>
    );
  }

  return (
    <div className="zip-page has-m-cards">
      <div className="zip-toolbar">
        <span className="zip-muted">{rows.length} akun • filter role &amp; status</span>
        <button
          type="button"
          className="zip-btn zip-btn-primary"
          onClick={() => {
            setView({ name: "tambah" });
            onMeta?.({ title: "Tambah Pengguna", subtitle: "Nama • username • email • password • role • status" });
          }}
        >
          + Tambah Pengguna
        </button>
      </div>
      {error && <div className="zip-error">{error}</div>}
      {!loading && resetReqs.length > 0 && (
        <div className="zip-note" style={{ marginBottom: 14 }}>
          <strong><i className="fa-solid fa-key"></i> {resetReqs.length} permintaan reset password</strong>
          {resetReqs.map((r) => (
            <div key={r.id} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 8, gap: 8 }}>
              <span style={{ fontSize: 13 }}>{r.user?.name} <span className="zip-muted">({r.user?.username})</span></span>
              <button
                type="button"
                className="zip-btn-primary"
                style={{ padding: "8px 16px", fontSize: 12 }}
                onClick={() => {
                  const u = rows.find((x) => x.id === r.user_id);
                  if (u) { setView({ name: "edit", row: u, resetRequestId: r.id }); onMeta?.({ title: "Reset Password", subtitle: u.name }); }
                }}
              >
                Atur Password Baru
              </button>
            </div>
          ))}
        </div>
      )}
      {loading ? (
        <SkelCards n={4} />
      ) : (
        <>
        <div className="zip-table-wrap">
          <table className="zip-table">
            <thead>
              <tr>
                <th>Pengguna</th>
                <th>Peran</th>
                <th>Status</th>
                <th style={{ textAlign: "right" }}>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((u) => (
                <tr key={u.id}>
                  <td><UserCell name={u.name} email={u.email} /></td>
                  <td><RoleBadge role={u.role} /></td>
                  <td><StatusBadge active={u.is_active} /></td>
                  <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                    <button type="button" className="zip-act zip-act-detail" style={{ marginRight: 6 }} onClick={() => { setView({ name: "detail", row: u }); onMeta?.({ title: "Detail Pengguna", subtitle: u.name }); }}>Detail</button>
                    <button type="button" className="zip-act zip-act-edit" style={{ marginRight: 6 }} onClick={() => { setView({ name: "edit", row: u }); onMeta?.({ title: "Detail / Edit Pengguna", subtitle: "Nama • username • email • password • role • status" }); }}>Edit</button>
                    <button type="button" className="zip-act zip-act-hapus" onClick={() => { setView({ name: "hapus", row: u }); onMeta?.({ title: "Konfirmasi Hapus", subtitle: "Aksi berisiko • butuh konfirmasi" }); }}>Hapus</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="m-cards">
          {rows.map((u) => (
            <div className="m-card" key={u.id}>
              <div className="m-card-top">
                <div className="m-card-tx">
                  <b>{u.name}</b>
                  <small>{u.email}</small>
                </div>
              </div>
              <div className="m-card-bottom">
                <RoleBadge role={u.role} />
                <StatusBadge active={u.is_active} />
              </div>
              <div className="m-card-actions">
                <button type="button" className="zip-act zip-act-detail" onClick={() => { setView({ name: "detail", row: u }); onMeta?.({ title: "Detail Pengguna", subtitle: u.name }); }}>Detail</button>
                <button type="button" className="zip-act zip-act-edit" onClick={() => { setView({ name: "edit", row: u }); onMeta?.({ title: "Detail / Edit Pengguna", subtitle: "Nama • username • email • password • role • status" }); }}>Edit</button>
                <button type="button" className="zip-act zip-act-hapus" onClick={() => { setView({ name: "hapus", row: u }); onMeta?.({ title: "Konfirmasi Hapus", subtitle: "Aksi berisiko • butuh konfirmasi" }); }}>Hapus</button>
              </div>
            </div>
          ))}
        </div>
        {rows.length === 0 && <p className="zip-muted">Belum ada pengguna.</p>}
        </>
      )}
    </div>
  );
}

export default Pengguna;
