import { useEffect, useState } from "react";
import { ep } from "../../api/endpoints.js";
import { API_BASE_URL, getToken } from "../../api/client.js";
import { authApi, profilesApi, documentsApi, applicationsApi, placementsApi, periodsApi } from "../../api/index.js";

function initials(name) {
  const p = String(name || "?").trim().split(/\s+/);
  if (p.length === 1) return p[0].slice(0, 2).toUpperCase();
  return (p[0][0] + p[p.length - 1][0]).toUpperCase();
}

/* ================= PROFIL SAYA ================= */
export function ProfilSiswa({ onMeta }) {
  const user = authApi.currentUser();
  const [profil, setProfil] = useState(null);
  // undefined = masih memuat, null = tidak ada periode yang bisa ditampilkan.
  const [periode, setPeriode] = useState(undefined);

  useEffect(() => {
    onMeta?.({ title: "Profil Saya", subtitle: "Data diri siswa" });
    (async () => {
      try {
        const res = await profilesApi.index({ user_id: user?.id }).catch(() => ({ data: [] }));
        setProfil((res?.data ?? [])[0] || null);
      } catch { /* abaikan */ }
    })();
    (async () => {
      try {
        // Periode dari penempatan aktif siswa; kalau belum ditempatkan,
        // pakai periode yang sedang berjalan di sekolah.
        const pRes = await placementsApi.index({ student_id: user?.id, status: "active" }).catch(() => ({ data: [] }));
        let periodId = (pRes?.data ?? [])[0]?.pkl_period_id || null;
        if (!periodId) {
          const all = await periodsApi.index().catch(() => ({ data: [] }));
          periodId = (all?.data ?? []).find((p) => p.is_active)?.id || null;
        }
        if (!periodId) { setPeriode(null); return; }
        const p = await periodsApi.show(periodId).catch(() => null);
        setPeriode(p?.data || null);
      } catch { setPeriode(null); }
    })();
  }, [onMeta, user?.id]);

  const fmtDate = (d) => {
    if (!d) return "";
    try {
      return new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" });
    } catch { return ""; }
  };
  const periodeText =
    periode === undefined ? "…" :
    !periode ? "—" :
    `${periode.name}${periode.start_date && periode.end_date ? ` (${fmtDate(periode.start_date)} – ${fmtDate(periode.end_date)})` : ""}`;

  const rows = [
    ["Nama Lengkap", user?.name || "—"],
    ["NIS", profil?.nis || user?.nis || "—"],
    ["Kelas", profil?.kelas || user?.kelas || "—"],
    ["Jurusan", profil?.jurusan || "—"],
    ["Periode PKL", periodeText],
    ["Email", user?.email || "—"],
    ["No. HP", profil?.phone || user?.phone || "—"],
    ["Alamat", profil?.address || "—"],
  ];

  return (
    <div className="siswa-card" style={{ maxWidth: 720 }}>
      <div className="siswa-profil-head">
        <span className="siswa-profil-av">{initials(user?.name)}</span>
        <div>
          <h3 className="siswa-card-title" style={{ fontSize: 19 }}>{user?.name || "Siswa"}</h3>
          <p className="siswa-sub">{profil?.kelas || user?.kelas || "Siswa"} • SiMagang</p>
          <span className="siswa-badge s-badge-green siswa-mt"><span className="dot"></span>Aktif</span>
        </div>
      </div>
      <hr style={{ border: 0, borderTop: "1px solid #f1f5f9", margin: "18px 0" }} />
      <dl className="siswa-dl">
        {rows.map(([k, v]) => (
          <div key={k} style={{ display: "contents" }}>
            <dt>{k}</dt><dd>{v}</dd>
          </div>
        ))}
      </dl>
      <p className="siswa-muted siswa-mt">
        <i className="fa-solid fa-circle-info"></i> Data profil dikelola oleh sekolah. Hubungi TU jika ada yang perlu diperbaiki.
      </p>
    </div>
  );
}

/* ================= DOKUMEN ================= */
export function DokumenSiswa({ onMeta }) {
  const [docs, setDocs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [msg, setMsg] = useState("");
  const [showLink, setShowLink] = useState(false);
  const [linkName, setLinkName] = useState("");
  const [linkUrl, setLinkUrl] = useState("");
  const [savingLink, setSavingLink] = useState(false);
  const [preview, setPreview] = useState(null); // {url, name, type}

  useEffect(() => {
    onMeta?.({ title: "Dokumen", subtitle: "Berkas persyaratan PKL" });
    muat();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [onMeta]);

  const muat = async () => {
    setLoading(true);
    try {
      const aRes = await applicationsApi.index().catch(() => ({ data: [] }));
      const apps = aRes?.data ?? [];
      const semua = [];
      for (const a of apps) {
        const dRes = await documentsApi.index({ application_id: a.id }).catch(() => ({ data: [] }));
        semua.push(...(dRes?.data ?? []));
      }
      setDocs(semua);
    } finally { setLoading(false); }
  };

  const getAppId = async () => {
    const aRes = await applicationsApi.index().catch(() => ({ data: [] }));
    return (aRes?.data ?? aRes ?? [])[0]?.id;
  };

  const upload = async (e) => {
    const f = e.target.files?.[0];
    if (!f) return;
    e.target.value = "";
    setUploading(true);
    setMsg("");
    try {
      const appId = await getAppId();
      if (!appId) { setMsg("Buat pengajuan PKL dulu sebelum mengunggah dokumen."); return; }
      const fd = new FormData();
      fd.append("application_id", appId);
      fd.append("document_name", f.name);
      fd.append("document_type", "others");
      fd.append("file", f);
      await documentsApi.store(fd);
      setMsg("Dokumen berhasil diunggah.");
      muat();
    } catch (err) {
      setMsg(err?.validationErrors ? Object.values(err.validationErrors).flat().join(" ") : (err?.message || "Gagal mengunggah dokumen."));
    } finally { setUploading(false); }
  };

  const simpanLink = async (e) => {
    e.preventDefault();
    if (!linkName.trim() || !linkUrl.trim()) { setMsg("Nama dan URL link harus diisi."); return; }
    setSavingLink(true);
    setMsg("");
    try {
      const appId = await getAppId();
      if (!appId) { setMsg("Buat pengajuan PKL dulu sebelum menambah link."); return; }
      await documentsApi.store({
        application_id: appId,
        document_name: linkName.trim(),
        document_type: "others",
        file_url: linkUrl.trim(),
      });
      setMsg("Link berhasil ditambahkan.");
      setLinkName(""); setLinkUrl(""); setShowLink(false);
      muat();
    } catch (err) {
      setMsg(err?.validationErrors ? Object.values(err.validationErrors).flat().join(" ") : (err?.message || "Gagal menambah link."));
    } finally { setSavingLink(false); }
  };

  const isUrl = (p) => /^https?:\/\//i.test(p || "");

  const lihat = async (d) => {
    try {
      const token = getToken();
      const res = await fetch(`${API_BASE_URL}${ep.applicationDocuments.download(d.id)}`, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      });
      if (!res.ok) throw new Error("Gagal memuat file.");
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      setPreview({ url, name: d.document_name, type: blob.type });
    } catch (err) {
      setMsg(err?.message || "Gagal memuat file.");
    }
  };

  const tutupPreview = () => {
    if (preview?.url) URL.revokeObjectURL(preview.url);
    setPreview(null);
  };

  const unduh = async (d) => {
    try {
      const token = getToken();
      const res = await fetch(`${API_BASE_URL}${ep.applicationDocuments.download(d.id)}`, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      });
      if (!res.ok) throw new Error("Gagal mengunduh.");
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = d.document_name || "dokumen";
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      setMsg(err?.message || "Gagal mengunduh dokumen.");
    }
  };

  const ikon = (t) => {
    if (/cv/i.test(t)) return "fa-id-card";
    if (/portofolio/i.test(t)) return "fa-briefcase";
    if (/sertifikat/i.test(t)) return "fa-award";
    return "fa-file-lines";
  };

  return (
    <div className="siswa-card" style={{ maxWidth: 760 }}>
      <div className="siswa-between" style={{ marginBottom: 8 }}>
        <div>
          <h3 className="siswa-card-title">Dokumen Persyaratan</h3>
          <p className="siswa-sub">CV, portofolio, dan berkas pendukung lainnya</p>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button type="button" className="siswa-btn siswa-btn-outline siswa-btn-sm" onClick={() => setShowLink(true)}>
            <i className="fa-solid fa-link"></i> Tambah Link
          </button>
          <label className="siswa-btn siswa-btn-primary siswa-btn-sm" style={{ cursor: "pointer" }}>
            <i className="fa-solid fa-upload"></i> {uploading ? "Mengunggah…" : "Unggah"}
            <input type="file" style={{ display: "none" }} onChange={upload} disabled={uploading} accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" />
          </label>
        </div>
      </div>
      {msg && <div className="siswa-ok">{msg}</div>}
      {loading ? <p className="siswa-muted">Memuat…</p> : docs.length === 0 ? (
        <div className="siswa-empty">
          <i className="fa-solid fa-folder-open"></i>
          Belum ada dokumen. Unggah CV dan portofoliomu!
        </div>
      ) : docs.map((d) => (
        <div className="siswa-doc" key={d.id}>
          <span className="siswa-doc-ic"><i className={`fa-solid ${isUrl(d.file_path) ? "fa-link" : ikon(d.document_type)}`}></i></span>
          <div className="siswa-doc-tx">
            <b>{d.document_name}</b>
            <small>{isUrl(d.file_path) ? d.file_path : d.document_type}</small>
          </div>
          {isUrl(d.file_path) ? (
            <a href={d.file_path} target="_blank" rel="noopener noreferrer" className="siswa-btn siswa-btn-outline siswa-btn-sm">
              <i className="fa-solid fa-arrow-up-right-from-square"></i> Buka
            </a>
          ) : (
            <div style={{ display: "flex", gap: 6 }}>
              <button type="button" onClick={() => lihat(d)} className="siswa-btn siswa-btn-outline siswa-btn-sm">
                <i className="fa-solid fa-eye"></i> Lihat
              </button>
              <button type="button" onClick={() => unduh(d)} className="siswa-btn siswa-btn-outline siswa-btn-sm">
                <i className="fa-solid fa-download"></i>
              </button>
            </div>
          )}
          <span className={`siswa-badge ${d.status === "verified" ? "s-badge-green" : d.status === "rejected" ? "s-badge-red" : "s-badge-amber"}`}>
            <span className="dot"></span>
            {d.status === "verified" ? "Terverifikasi" : d.status === "rejected" ? "Ditolak" : "Menunggu"}
          </span>
        </div>
      ))}
      {preview && (
        <div className="siswa-modal-ov" onClick={tutupPreview}>
          <div className="siswa-modal" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 640 }}>
            <div className="siswa-between" style={{ marginBottom: 12 }}>
              <h3 className="siswa-card-title" style={{ margin: 0 }}>{preview.name}</h3>
              <button type="button" className="siswa-btn siswa-btn-outline siswa-btn-sm" onClick={tutupPreview}>
                <i className="fa-solid fa-xmark"></i>
              </button>
            </div>
            {preview.type.startsWith("image/") ? (
              <img src={preview.url} alt={preview.name} style={{ width: "100%", borderRadius: 8 }} />
            ) : preview.type === "application/pdf" ? (
              <iframe src={preview.url} title={preview.name} style={{ width: "100%", height: 480, border: 0, borderRadius: 8 }} />
            ) : (
              <p className="siswa-muted">Preview tidak tersedia untuk tipe file ini. Silakan unduh.</p>
            )}
          </div>
        </div>
      )}
      {showLink && (
        <div className="siswa-modal-ov" onClick={() => setShowLink(false)}>
          <div className="siswa-modal" onClick={(e) => e.stopPropagation()}>
            <h3 className="siswa-card-title">Tambah Link Dokumen</h3>
            <p className="siswa-sub" style={{ marginBottom: 14 }}>Misal link Google Drive portofolio, LinkedIn, atau GitHub.</p>
            <form onSubmit={simpanLink}>
              <label className="siswa-field">
                <span>Nama dokumen</span>
                <input type="text" value={linkName} onChange={(e) => setLinkName(e.target.value)} placeholder="cth: Portofolio Desain" />
              </label>
              <label className="siswa-field">
                <span>URL</span>
                <input type="url" value={linkUrl} onChange={(e) => setLinkUrl(e.target.value)} placeholder="https://..." />
              </label>
              <div className="siswa-form-actions">
                <button type="button" className="siswa-btn siswa-btn-outline" onClick={() => setShowLink(false)}>Batal</button>
                <button type="submit" className="siswa-btn siswa-btn-primary" disabled={savingLink}>{savingLink ? "Menyimpan…" : "Simpan Link"}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
