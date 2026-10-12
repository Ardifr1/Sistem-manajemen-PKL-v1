import { useEffect, useState } from "react";
import { companiesApi } from "../../api/index.js";
import { getMyCompanyId } from "../../lib/role-data.js";

/* Profil perusahaan — kelola data perusahaan sendiri. */
const DOC_OPTIONS = ["CV", "Portofolio", "Sertifikat", "Transkrip Nilai / Rapor", "Surat Pengantar Sekolah", "Pas Foto"];

function parseDocs(v) {
  if (!v) return [];
  try {
    const a = JSON.parse(v);
    if (Array.isArray(a)) return a;
  } catch { /* bukan JSON */ }
  return String(v).split(",").map((s) => s.trim()).filter(Boolean);
}

function ProfilPerusahaan({ onMeta }) {
  const [form, setForm] = useState({ name: "", industry: "", address: "", phone: "", email: "", description: "", student_quota: "", required_skills: "" });
  const [docs, setDocs] = useState([]);
  const [customDoc, setCustomDoc] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [companyId, setCompanyId] = useState(null);

  useEffect(() => {
    onMeta?.({ title: "Profil Perusahaan", subtitle: "Kelola data perusahaan Anda" });
    (async () => {
      try {
        const id = await getMyCompanyId();
        setCompanyId(id);
        if (id) {
          const c = await companiesApi.show(id);
          const d = c?.data || c;
          setForm({
            name: d.name || "",
            industry: d.industry || "",
            address: d.address || "",
            phone: d.phone || "",
            email: d.email || "",
            description: d.description || "",
            student_quota: d.student_quota ?? "",
            required_skills: d.required_skills || "",
          });
          setDocs(parseDocs(d.required_documents));
        }
      } finally {
        setLoading(false);
      }
    })();
  }, [onMeta]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const toggleDoc = (d) => setDocs((prev) => prev.includes(d) ? prev.filter((x) => x !== d) : [...prev, d]);
  const addCustomDoc = () => {
    const v = customDoc.trim();
    if (v && !docs.includes(v)) setDocs((prev) => [...prev, v]);
    setCustomDoc("");
  };

  const submit = async (e) => {
    e.preventDefault();
    if (!companyId) return;
    setSaving(true);
    try {
      await companiesApi.update(companyId, { ...form, student_quota: Number(form.student_quota) || 0, required_documents: JSON.stringify(docs) });
      alert("Profil tersimpan.");
    } catch (err) {
      alert(err?.message || "Gagal menyimpan profil.");
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="zip-muted">Memuat…</div>;

  return (
    <div className="zip-page">
      <form className="zip-card" style={{ maxWidth: "none" }} onSubmit={submit}>
        <div className="zip-form-grid">
          <label className="zip-field"><span>Nama Perusahaan</span><input value={form.name} onChange={set("name")} /></label>
          <label className="zip-field"><span>Bidang Industri</span><input value={form.industry} onChange={set("industry")} /></label>
          <label className="zip-field"><span>Email</span><input type="email" value={form.email} onChange={set("email")} /></label>
          <label className="zip-field"><span>No. Telepon</span><input value={form.phone} onChange={set("phone")} /></label>
          <label className="zip-field"><span>Kuota Siswa</span><input type="number" min="0" value={form.student_quota} onChange={set("student_quota")} /></label>
          <label className="zip-field"><span>Alamat</span><input value={form.address} onChange={set("address")} /></label>
        </div>
        <label className="zip-field"><span>Deskripsi</span>
          <textarea rows="3" value={form.description} onChange={set("description")} placeholder="Tentang perusahaan…" />
        </label>
        <label className="zip-field"><span>Keahlian yang Dibutuhkan</span>
          <input value={form.required_skills} onChange={set("required_skills")} placeholder="cth Backend, Frontend, QA" />
        </label>
        <div className="zip-field"><span>Persyaratan Dokumen untuk Pelamar</span>
          <div className="zip-doc-grid">
            {DOC_OPTIONS.map((d) => (
              <label key={d} className={`zip-check${docs.includes(d) ? " on" : ""}`}>
                <input type="checkbox" checked={docs.includes(d)} onChange={() => toggleDoc(d)} />
                {d}
              </label>
            ))}
          </div>
          <div className="zip-doc-add">
            <input value={customDoc} onChange={(e) => setCustomDoc(e.target.value)}
              placeholder="Tambah persyaratan lain…" onKeyDown={(e) => e.key === "Enter" && (e.preventDefault(), addCustomDoc())} />
            <button type="button" className="zip-btn-outline" onClick={addCustomDoc}>Tambah</button>
          </div>
          {docs.filter((d) => !DOC_OPTIONS.includes(d)).length > 0 && (
            <div className="zip-doc-tags">
              {docs.filter((d) => !DOC_OPTIONS.includes(d)).map((d) => (
                <span key={d} className="zip-doc-tag">{d}
                  <button type="button" onClick={() => toggleDoc(d)} aria-label={`Hapus ${d}`}>
                    <i className="fa-solid fa-xmark"></i>
                  </button>
                </span>
              ))}
            </div>
          )}
          <small className="zip-hint">Dokumen ini wajib dilampirkan siswa saat mengajukan PKL ke perusahaan Anda.</small>
        </div>
        <div className="zip-form-actions">
          <button type="submit" className="zip-btn-primary" disabled={saving}>{saving ? "Menyimpan…" : "Simpan Profil"}</button>
        </div>
      </form>
    </div>
  );
}

export default ProfilPerusahaan;
