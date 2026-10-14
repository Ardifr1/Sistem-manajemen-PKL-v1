import { useEffect, useState } from "react";
import { componentsApi, finalsApi, placementsApi, auditLogsApi } from "../../api/index.js";
import "./admin-pages.css";

/* Admin: kelola komponen penilaian (bobot awal 70 industri / 30 guru,
 * dapat diubah; total aktif harus tepat 100) + publikasi nilai akhir. */

function KomponenForm({ initial, onCancel, onSaved }) {
  const [form, setForm] = useState({
    name: initial?.name || "",
    description: initial?.description || "",
    owner_type: initial?.owner_type || "industri",
    weight: initial?.weight ?? "",
    is_active: initial ? !!initial.is_active : true,
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const submit = async (e) => {
    e.preventDefault();
    setError("");
    if (!form.name.trim() || form.weight === "") {
      setError("Nama dan bobot wajib diisi.");
      return;
    }
    setSaving(true);
    try {
      const payload = {
        name: form.name.trim(),
        description: form.description.trim() || null,
        owner_type: form.owner_type,
        weight: Number(form.weight),
        is_active: !!form.is_active,
      };
      if (initial?.id) await componentsApi.update(initial.id, payload);
      else await componentsApi.store(payload);
      onSaved();
    } catch (err) {
      setError(err?.message || "Gagal menyimpan komponen.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <form className="zip-card" onSubmit={submit} style={{ marginBottom: 16 }}>
      {error && <div className="zip-error">{error}</div>}
      <div className="zip-form-grid">
        <label className="zip-field">
          <span>Nama komponen</span>
          <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="cth Kedisiplinan" />
        </label>
        <label className="zip-field">
          <span>Pemilik</span>
          <select value={form.owner_type} onChange={(e) => setForm((f) => ({ ...f, owner_type: e.target.value }))}>
            <option value="industri">Pembimbing Industri</option>
            <option value="guru">Guru Pembimbing</option>
          </select>
        </label>
        <label className="zip-field">
          <span>Bobot (%)</span>
          <input type="number" min="0" max="100" step="0.01" value={form.weight} onChange={(e) => setForm((f) => ({ ...f, weight: e.target.value }))} />
        </label>
        <label className="zip-field">
          <span>Status</span>
          <select value={form.is_active ? "1" : "0"} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === "1" }))}>
            <option value="1">Aktif</option>
            <option value="0">Nonaktif</option>
          </select>
        </label>
      </div>
      <label className="zip-field" style={{ marginTop: 12 }}>
        <span>Deskripsi</span>
        <textarea rows="2" value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />
      </label>
      <div className="zip-form-actions">
        <button type="button" className="zip-btn-outline" onClick={onCancel}>Batal</button>
        <button type="submit" className="zip-btn-primary" disabled={saving}>{saving ? "Menyimpan…" : "Simpan"}</button>
      </div>
    </form>
  );
}

function PenilaianAdmin({ onMeta }) {
  const [components, setComponents] = useState([]);
  const [finals, setFinals] = useState([]);
  const [placements, setPlacements] = useState([]);
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const [editing, setEditing] = useState(null);
  const [showForm, setShowForm] = useState(false);
  const [acting, setActing] = useState(null);
  const [unpublishFor, setUnpublishFor] = useState(null);
  const [reason, setReason] = useState("");

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const [cRes, fRes, pRes, lRes] = await Promise.all([
        componentsApi.index().catch(() => ({ data: [] })),
        finalsApi.index().catch(() => ({ data: [] })),
        placementsApi.index().catch(() => ({ data: [] })),
        auditLogsApi.index().catch(() => ({ data: [] })),
      ]);
      setComponents(cRes?.data ?? []);
      setFinals(fRes?.data ?? []);
      setPlacements(Array.isArray(pRes) ? pRes : pRes?.data ?? []);
      setLogs(lRes?.data ?? []);
    } catch (err) {
      setError(err?.message || "Gagal memuat data penilaian.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    onMeta?.({ title: "Penilaian Akhir", subtitle: "Komponen bobot • publikasi nilai" });
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const totalAktif = components.filter((c) => c.is_active).reduce((n, c) => n + Number(c.weight || 0), 0);
  const placementOf = (id) => placements.find((p) => String(p.id) === String(id));
  const studentName = (pid) => placementOf(pid)?.student?.name || `Placement #${pid}`;

  const hapus = async (c) => {
    if (!window.confirm(`Hapus komponen "${c.name}"?`)) return;
    setError("");
    try {
      await componentsApi.destroy(c.id);
      load();
    } catch (err) {
      setError(err?.message || "Gagal menghapus komponen.");
    }
  };

  const publish = async (f) => {
    setActing(f.id);
    setError("");
    setSuccess("");
    try {
      await finalsApi.publish(f.id);
      setSuccess(`Nilai ${studentName(f.placement_id)} dipublikasikan.`);
      load();
    } catch (err) {
      setError(err?.message || "Gagal mempublikasikan nilai.");
    } finally {
      setActing(null);
    }
  };

  const unpublish = async () => {
    if (!unpublishFor) return;
    if (reason.trim().length < 5) {
      setError("Alasan pembatalan wajib diisi (min. 5 karakter).");
      return;
    }
    setActing(unpublishFor.id);
    setError("");
    setSuccess("");
    try {
      await finalsApi.unpublish(unpublishFor.id, reason.trim());
      setSuccess(`Publikasi ${studentName(unpublishFor.placement_id)} dibatalkan.`);
      setUnpublishFor(null);
      setReason("");
      load();
    } catch (err) {
      setError(err?.message || "Gagal membatalkan publikasi.");
    } finally {
      setActing(null);
    }
  };

  return (
    <div className="zip-page">
      {error && <div className="zip-error">{error}</div>}
      {success && <div className="zip-success">{success}</div>}

      <div className="zip-card" style={{ maxWidth: "none", marginBottom: 18 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8 }}>
          <div>
            <h3 className="zip-card-title" style={{ margin: 0 }}>Komponen Penilaian</h3>
            <div className="zip-sub">
              Bobot awal 70% Industri + 30% Guru — dapat diubah admin. Total aktif harus tepat 100%.
              Saat ini: <b>{totalAktif}</b>%.
            </div>
          </div>
          <button type="button" className="zip-btn-primary" onClick={() => { setEditing(null); setShowForm((s) => !s); }}>
            + Komponen
          </button>
        </div>

        {showForm && (
          <KomponenForm
            initial={editing}
            onCancel={() => { setShowForm(false); setEditing(null); }}
            onSaved={() => { setShowForm(false); setEditing(null); load(); }}
          />
        )}

        {loading ? <p className="zip-muted">Memuat…</p> : (
          <div className="zip-table-wrap" style={{ boxShadow: "none" }}>
            <table className="zip-table">
              <thead>
                <tr>
                  <th>Nama</th>
                  <th>Pemilik</th>
                  <th style={{ textAlign: "center" }}>Bobot</th>
                  <th style={{ textAlign: "center" }}>Status</th>
                  <th style={{ textAlign: "center" }}>Aksi</th>
                </tr>
              </thead>
              <tbody>
                {components.map((c) => (
                  <tr key={c.id}>
                    <td><b>{c.name}</b><div className="zip-sub">{c.description || "-"}</div></td>
                    <td>{c.owner_type === "guru" ? "Guru" : "Industri"}</td>
                    <td style={{ textAlign: "center" }}>{c.weight}%</td>
                    <td style={{ textAlign: "center" }}>{c.is_active ? "Aktif" : "Nonaktif"}</td>
                    <td style={{ textAlign: "center" }}>
                      <button type="button" className="zip-btn-outline" onClick={() => { setEditing(c); setShowForm(true); }}>Ubah</button>{" "}
                      <button type="button" className="zip-btn-outline" onClick={() => hapus(c)}>Hapus</button>
                    </td>
                  </tr>
                ))}
                {components.length === 0 && (
                  <tr><td colSpan="5" style={{ textAlign: "center" }} className="zip-muted">Belum ada komponen.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="zip-card" style={{ maxWidth: "none", marginBottom: 18 }}>
        <h3 className="zip-card-title" style={{ marginTop: 0 }}>Publikasi Nilai Akhir</h3>
        <p className="zip-sub">Hanya PKL berstatus selesai dengan perhitungan valid yang dapat dipublikasikan. Pembatalan wajib alasan dan tercatat di audit log.</p>
        {loading ? <p className="zip-muted">Memuat…</p> : (
          <div className="zip-table-wrap" style={{ boxShadow: "none" }}>
            <table className="zip-table">
              <thead>
                <tr>
                  <th>Siswa</th>
                  <th style={{ textAlign: "center" }}>Nilai</th>
                  <th style={{ textAlign: "center" }}>Status</th>
                  <th style={{ textAlign: "center" }}>Aksi</th>
                </tr>
              </thead>
              <tbody>
                {finals.map((f) => (
                  <tr key={f.id}>
                    <td>{studentName(f.placement_id)}</td>
                    <td style={{ textAlign: "center", fontWeight: 800 }}>{f.final_score}</td>
                    <td style={{ textAlign: "center" }}>
                      <span className="zip-badge b-gray">{f.status}</span>
                    </td>
                    <td style={{ textAlign: "center" }}>
                      {f.status === "published" ? (
                        <button type="button" className="zip-btn-outline" disabled={acting === f.id} onClick={() => { setUnpublishFor(f); setReason(""); }}>
                          Batalkan
                        </button>
                      ) : (
                        <button type="button" className="zip-btn-primary" disabled={acting === f.id} onClick={() => publish(f)}>
                          {acting === f.id ? "Memproses…" : "Publikasikan"}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {finals.length === 0 && (
                  <tr><td colSpan="4" style={{ textAlign: "center" }} className="zip-muted">Belum ada nilai akhir.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {unpublishFor && (
        <div className="zip-card" style={{ maxWidth: "none", marginBottom: 18 }}>
          <h3 className="zip-card-title" style={{ marginTop: 0 }}>
            Batalkan publikasi — {studentName(unpublishFor.placement_id)}
          </h3>
          <label className="zip-field">
            <span>Alasan pembatalan (wajib)</span>
            <textarea rows="3" value={reason} onChange={(e) => setReason(e.target.value)}
              placeholder="cth Nilai industri salah input, perlu verifikasi ulang…" />
          </label>
          <div className="zip-form-actions">
            <button type="button" className="zip-btn-outline" onClick={() => setUnpublishFor(null)}>Batal</button>
            <button type="button" className="zip-btn-primary" disabled={acting === unpublishFor.id} onClick={unpublish}>
              {acting === unpublishFor.id ? "Memproses…" : "Batalkan Publikasi"}
            </button>
          </div>
        </div>
      )}

      <div className="zip-card" style={{ maxWidth: "none" }}>
        <h3 className="zip-card-title" style={{ marginTop: 0 }}>Audit Log Publikasi</h3>
        {logs.length === 0 ? (
          <p className="zip-muted">Belum ada riwayat publikasi.</p>
        ) : (
          <div className="zip-table-wrap" style={{ boxShadow: "none" }}>
            <table className="zip-table">
              <thead>
                <tr>
                  <th>Waktu</th>
                  <th>Aksi</th>
                  <th>Siswa</th>
                  <th>Status</th>
                  <th>Alasan</th>
                </tr>
              </thead>
              <tbody>
                {logs.slice(0, 20).map((l) => (
                  <tr key={l.id}>
                    <td>{l.created_at ? new Date(l.created_at).toLocaleString("id-ID") : "-"}</td>
                    <td>{l.action}</td>
                    <td>{studentName(l.placement_id)}</td>
                    <td>{l.old_status || "-"} → {l.new_status || "-"}</td>
                    <td>{l.reason || "-"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

export default PenilaianAdmin;
