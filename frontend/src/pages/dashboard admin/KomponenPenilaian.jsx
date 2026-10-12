import { useEffect, useState } from "react";
import { componentsApi } from "../../api/index.js";
import { SkelCards } from "../../components/role/skeleton.jsx";

const PERAN = [
  { value: "teacher", label: "Guru Pembimbing" },
  { value: "supervisor", label: "Pembimbing Industri" },
];

function peranLabel(v) {
  return PERAN.find((p) => p.value === v)?.label || v;
}

/**
 * Komponen Penilaian (admin): atur komponen + bobot + peran penilai.
 * Dipakai guru & pembimbing industri saat menilai; sistem menghitung
 * nilai akhir otomatis dari bobot ini.
 */
function KomponenPenilaian({ onMeta }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ id: null, name: "", description: "", weight: "", assessor_role: "teacher", is_active: true });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [editing, setEditing] = useState(false);

  const load = async () => {
    setLoading(true);
    try {
      const res = await componentsApi.index().catch(() => ({ data: [] }));
      setRows(Array.isArray(res?.data) ? res.data : res ?? []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    onMeta?.({ title: "Komponen Penilaian", subtitle: "Atur komponen, bobot & peran penilai" });
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [onMeta]);

  const totalBobot = rows.filter((r) => r.is_active !== false).reduce((a, b) => a + Number(b.weight || 0), 0);

  const resetForm = () => {
    setForm({ id: null, name: "", description: "", weight: "", assessor_role: "teacher", is_active: true });
    setEditing(false);
    setError("");
  };

  const submit = async (e) => {
    e.preventDefault();
    setError("");
    if (!form.name.trim()) { setError("Nama komponen wajib diisi."); return; }
    const w = Number(form.weight);
    if (isNaN(w) || w < 0 || w > 100) { setError("Bobot harus angka 0–100."); return; }
    setSaving(true);
    try {
      const payload = {
        name: form.name.trim(),
        description: form.description.trim() || null,
        weight: w,
        assessor_role: form.assessor_role,
        is_active: form.is_active,
      };
      if (form.id) await componentsApi.update(form.id, payload);
      else await componentsApi.store(payload);
      resetForm();
      await load();
    } catch (err) {
      setError(err?.message || "Gagal menyimpan komponen.");
    } finally {
      setSaving(false);
    }
  };

  const hapus = async (id) => {
    if (!window.confirm("Hapus komponen ini? Nilai yang sudah tersimpan tidak ikut terhapus.")) return;
    try {
      await componentsApi.destroy(id);
      await load();
    } catch (err) {
      setError(err?.message || "Gagal menghapus komponen.");
    }
  };

  const toggleAktif = async (row) => {
    try {
      await componentsApi.update(row.id, { is_active: !row.is_active });
      await load();
    } catch (err) {
      setError(err?.message || "Gagal mengubah status.");
    }
  };

  return (
    <div className="zip-page has-m-cards">
      {error && <div className="zip-error">{error}</div>}

      <div className="zip-card" style={{ maxWidth: "none", marginBottom: 16 }}>
        <div className="siswa-between" style={{ marginBottom: 8 }}>
          <h3 className="zip-card-title" style={{ margin: 0 }}>Total Bobot Aktif</h3>
          <span className={`zip-badge ${Math.abs(totalBobot - 100) < 0.01 ? "b-green" : "b-amber"}`}>
            {totalBobot}%
          </span>
        </div>
        <p className="zip-sub" style={{ margin: 0 }}>
          Idealnya total 100%. Sistem menormalisasi otomatis bila tidak pas 100,
          tapi disarankan tetap 100 agar mudah dibaca.
        </p>
      </div>

      <div className="zip-card" style={{ maxWidth: "none", marginBottom: 16 }}>
        <h3 className="zip-card-title" style={{ marginTop: 0 }}>{editing ? "Edit Komponen" : "Tambah Komponen"}</h3>
        <form onSubmit={submit}>
          <div className="zip-form-grid">
            <label className="zip-field">
              <span>Nama Komponen</span>
              <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="cth Kedisiplinan" />
            </label>
            <label className="zip-field">
              <span>Bobot (%)</span>
              <input type="number" min="0" max="100" step="0.01" value={form.weight} onChange={(e) => setForm((f) => ({ ...f, weight: e.target.value }))} placeholder="cth 20" />
            </label>
            <label className="zip-field">
              <span>Dinilai oleh</span>
              <select value={form.assessor_role} onChange={(e) => setForm((f) => ({ ...f, assessor_role: e.target.value }))}>
                {PERAN.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
              </select>
            </label>
            <label className="zip-field">
              <span>Status</span>
              <select value={form.is_active ? "1" : "0"} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === "1" }))}>
                <option value="1">Aktif</option>
                <option value="0">Nonaktif</option>
              </select>
            </label>
          </div>
          <label className="zip-field">
            <span>Deskripsi / Deskriptor (opsional)</span>
            <textarea rows="2" value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} placeholder="Penjelasan perilaku yang dinilai…" />
          </label>
          <div className="zip-form-actions">
            {editing && <button type="button" className="zip-btn-outline" onClick={resetForm}>Batal</button>}
            <button type="submit" className="zip-btn-primary" disabled={saving}>
              {saving ? "Menyimpan…" : editing ? "Simpan Perubahan" : "Tambah Komponen"}
            </button>
          </div>
        </form>
      </div>

      <div className="zip-card" style={{ maxWidth: "none" }}>
        <h3 className="zip-card-title" style={{ marginTop: 0 }}>Daftar Komponen</h3>
        {loading ? <SkelCards n={3} /> : rows.length === 0 ? (
          <p className="zip-muted">Belum ada komponen. Tambahkan di form atas.</p>
        ) : (
          <>
            <div className="zip-table-wrap">
              <table className="zip-table">
                <thead><tr><th>Komponen</th><th>Bobot</th><th>Dinilai oleh</th><th>Status</th><th style={{ textAlign: "right" }}>Aksi</th></tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.id} style={r.is_active === false ? { opacity: 0.55 } : undefined}>
                      <td><b>{r.name}</b>{r.description ? <div className="zip-muted" style={{ fontSize: 12 }}>{r.description}</div> : null}</td>
                      <td><span className="zip-badge b-blue">{r.weight}%</span></td>
                      <td>{peranLabel(r.assessor_role)}</td>
                      <td>
                        <button type="button" className={`zip-badge ${r.is_active !== false ? "b-green" : "b-gray"}`} style={{ cursor: "pointer", border: 0 }} onClick={() => toggleAktif(r)}>
                          {r.is_active !== false ? "Aktif" : "Nonaktif"}
                        </button>
                      </td>
                      <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                        <button type="button" className="zip-act zip-act-edit" style={{ marginRight: 6 }}
                          onClick={() => { setForm({ id: r.id, name: r.name || "", description: r.description || "", weight: r.weight ?? "", assessor_role: r.assessor_role || "teacher", is_active: r.is_active !== false }); setEditing(true); setError(""); window.scrollTo({ top: 0, behavior: "smooth" }); }}>
                          Edit
                        </button>
                        <button type="button" className="zip-act zip-act-hapus" onClick={() => hapus(r.id)}>Hapus</button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="m-cards">
              {rows.map((r) => (
                <div className="m-card" key={r.id}>
                  <div className="m-card-top">
                    <div className="m-card-tx">
                      <b>{r.name}</b>
                      <small>{peranLabel(r.assessor_role)}</small>
                    </div>
                  </div>
                  <div className="m-card-bottom">
                    <span className="zip-badge b-blue">{r.weight}%</span>
                    <span className={`zip-badge ${r.is_active !== false ? "b-green" : "b-gray"}`}>{r.is_active !== false ? "Aktif" : "Nonaktif"}</span>
                  </div>
                  <div className="m-card-actions">
                    <button type="button" className="zip-act zip-act-edit" onClick={() => { setForm({ id: r.id, name: r.name || "", description: r.description || "", weight: r.weight ?? "", assessor_role: r.assessor_role || "teacher", is_active: r.is_active !== false }); setEditing(true); }}>Edit</button>
                    <button type="button" className="zip-act zip-act-hapus" onClick={() => hapus(r.id)}>Hapus</button>
                  </div>
                </div>
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  );
}

export default KomponenPenilaian;
