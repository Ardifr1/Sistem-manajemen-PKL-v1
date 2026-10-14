import { useEffect, useState } from "react";
import { companiesApi, placementsApi, usersApi } from "../../api/index.js";
import { SkelCards } from "../../components/role/skeleton.jsx";
import "./admin-pages.css";

function CompanyForm({ initial, onCancel, onSaved }) {
  const [form, setForm] = useState({
    name: initial?.name || "",
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
    setSaving(true);
    try {
      const payload = {
        name: form.name.trim(),
        is_active: form.is_active,
      };
      if (initial?.id) await companiesApi.update(initial.id, payload);
      else await companiesApi.store(payload);
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
      <div className="zip-info-box" style={{ marginBottom: 4 }}>
        <i className="fa-solid fa-circle-info"></i>
        <span>Bidang & kuota diatur sendiri oleh perusahaan lewat menu Profil Perusahaan.</span>
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

  const load = async () => {
    setLoading(true);
    try {
      const [cRes, pRes, sRes] = await Promise.all([
        companiesApi.index().catch(() => ({ data: [] })),
        placementsApi.index().catch(() => ({ data: [] })),
        usersApi.index({ role: "student" }).catch(() => ({ data: [] })),
      ]);
      setRows(Array.isArray(cRes.data) ? cRes.data : []);
      setPlacements(Array.isArray(pRes.data) ? pRes.data : []);
      setStudents(Array.isArray(sRes.data) ? sRes.data : []);
    } catch (err) {
      setError(err?.message || "Gagal memuat perusahaan.");
    } finally {
      setLoading(false);
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
  const terisiOf = (cid) => placements.filter((pl) => String(pl.company_id) === String(cid)).length;

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
            onMeta?.({ title: "Tambah Perusahaan", subtitle: "Nama • bidang • kuota • status" });
          }}
        >
          + Tambah Perusahaan
        </button>
      </div>
      {error && <div className="zip-error">{error}</div>}
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
                <button type="button" className="zip-act zip-act-edit" onClick={() => { setView({ name: "edit", row: c }); onMeta?.({ title: "Edit Perusahaan", subtitle: "Nama • bidang • kuota • status" }); }}>Edit</button>
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
