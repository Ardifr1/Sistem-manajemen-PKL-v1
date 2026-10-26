import { useEffect, useState } from "react";
import { applicationsApi, interviewsApi } from "../../api/index.js";

/* Halaman Seleksi & Interview — jadwal + hasil + form jadwalkan (tersambung ke API interviews). */
function SeleksiInterview({ onMeta }) {
  const [items, setItems] = useState([]);
  const [selected, setSelected] = useState(null);
  const [loading, setLoading] = useState(true);
  const [apps, setApps] = useState([]);
  const [allApps, setAllApps] = useState([]);
  const [aksiMsg, setAksiMsg] = useState("");
  const [aksiError, setAksiError] = useState("");
  const [memutuskan, setMemutuskan] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState("");
  const [formOk, setFormOk] = useState("");
  const [form, setForm] = useState({ application_id: "", mode: "offline", date: "", time: "", link: "", pewawancara: "" });

  const load = async () => {
    try {
      const [ir, ar] = await Promise.all([
        interviewsApi.index().catch(() => ({ data: [] })),
        applicationsApi.index().catch(() => ({ data: [] })),
      ]);
      const ivs = Array.isArray(ir) ? ir : ir?.data || [];
      const a = Array.isArray(ar) ? ar : ar?.data || [];
      setItems(ivs);
      setAllApps(a);
      setApps(a.filter((x) => ["pending", "interview"].includes(x.status)));
      if (ivs[0] && !selected) setSelected(ivs[0]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    onMeta?.({ title: "Seleksi & Interview", subtitle: "Jadwal interview • hasil penilaian" });
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [onMeta]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const resetForm = () => {
    setForm({ application_id: "", mode: "offline", date: "", time: "", link: "", pewawancara: "" });
    setEditingId(null);
    setFormError("");
  };

  const simpanJadwal = async () => {
    setFormError("");
    setFormOk("");
    if (!form.application_id) { setFormError("Pilih siswa/lamaran dulu."); return; }
    if (!form.date || !form.time) { setFormError("Tanggal dan jam wajib diisi."); return; }
    setSaving(true);
    try {
      const payload = {
        application_id: Number(form.application_id),
        scheduled_at: `${form.date} ${form.time}:00`,
        mode: form.mode,
        place: form.link,
        note: form.pewawancara ? `Pewawancara: ${form.pewawancara}` : "",
        status: "scheduled",
      };
      if (editingId) {
        await interviewsApi.update(editingId, payload);
        setFormOk("Jadwal berhasil diperbarui.");
      } else {
        await interviewsApi.store(payload);
        await applicationsApi.update(Number(form.application_id), { status: "interview" }).catch(() => {});
        setFormOk("Jadwal berhasil disimpan.");
      }
      resetForm();
      await load();
    } catch (e) {
      setFormError(e?.message || "Gagal menyimpan jadwal.");
    } finally {
      setSaving(false);
    }
  };

  const jadwalUlang = (iv) => {
    const [d, t] = String(iv.scheduled_at || "").split(/[T ]/);
    setEditingId(iv.id);
    setForm({
      application_id: String(iv.application_id || ""),
      mode: iv.mode || "offline",
      date: d || "",
      time: (t || "").slice(0, 5),
      link: iv.place || "",
      pewawancara: String(iv.note || "").replace(/^Pewawancara:\s*/, ""),
    });
    setFormError("");
    setFormOk("");
    window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" });
  };

  const appName = (id) => {
    const a = apps.find((x) => String(x.id) === String(id));
    return a ? `${a.student?.name || "-"} • ${a.position || a.division || "-"}` : "-";
  };

  const statusLamaran = (s) =>
    s === "accepted" ? "Diterima" :
    s === "rejected" ? "Ditolak" :
    s === "interview" ? "Interview" :
    s === "seleksi" ? "Dalam Seleksi" :
    s === "approved" ? "Disetujui (penempatan)" :
    s === "pending" ? "Diajukan" : (s || "—");

  const appOfSelected = selected ? allApps.find((x) => String(x.id) === String(selected.application_id)) : null;

  // Keputusan hasil interview. Berbeda dari versi lama: error TIDAK lagi
  // ditelan diam-diam (dulu .catch(() => {}) sehingga tombol tampak tidak
  // melakukan apa-apa), dan selalu ada pesan sukses/gagal yang terlihat.
  const putuskan = async (hasil) => {
    if (!selected || memutuskan) return;
    setAksiMsg("");
    setAksiError("");
    setMemutuskan(true);
    try {
      const res = await interviewsApi.update(selected.id, { status: "done", result: hasil });
      if (selected.application_id) {
        await applicationsApi.update(selected.application_id, { status: hasil === "passed" ? "accepted" : "rejected" });
      }
      if (res?.data) setSelected(res.data);
      setAksiMsg(hasil === "passed"
        ? "Siswa dinyatakan lulus dan lamaran diterima. Siswa kini dapat memilih tawaran ini di Pilih Final."
        : "Siswa dinyatakan tidak lulus dan lamaran ditolak.");
      await load();
    } catch (e) {
      setAksiError(e?.message || "Gagal memperbarui hasil interview.");
    } finally {
      setMemutuskan(false);
    }
  };

  return (
    <div className="zip-page">
      {loading && <div className="zip-muted">Memuat…</div>}
      <div className="zip-cols2">
        <div className="zip-card">
          <h3 className="zip-card-title" style={{ marginTop: 0 }}>Jadwal Interview</h3>
          {items.length === 0 && <div className="zip-muted">Belum ada jadwal interview.</div>}
          {items.map((x) => (
            <div key={x.id} className={`zip-iv${selected?.id === x.id ? " active" : ""}`} onClick={() => { setSelected(x); setAksiMsg(""); setAksiError(""); }}>
              <span className={`zip-badge ${x.status === "done" ? "b-green" : x.status === "cancelled" ? "b-red" : "b-blue"}`} style={{ float: "right" }}>
                {x.status === "done" ? "Selesai" : x.status === "cancelled" ? "Batal" : "Terjadwal"}
              </span>
              <div className="time">{String(x.scheduled_at || "—").slice(0, 16).replace("T", " ")}</div>
              <div className="nm">{x.application?.student?.name || appName(x.application_id)}</div>
              <div className="meta">{x.mode === "online" ? "Online" : "Offline"} • {x.place || "-"}</div>
            </div>
          ))}
        </div>
        <div className="zip-card">
          <h3 className="zip-card-title" style={{ marginTop: 0 }}>Hasil Interview{selected ? ` — ${selected.application?.student?.name || appName(selected.application_id)}` : ""}</h3>
          {!selected && <div className="zip-muted">Pilih jadwal di kiri.</div>}
          {selected && (
            <>
              <div className="zip-kv"><span>Jadwal</span><b>{String(selected.scheduled_at || "-").slice(0, 16).replace("T", " ")}</b></div>
              <div className="zip-kv"><span>Mode</span><b>{selected.mode === "online" ? "Online" : "Offline"}</b></div>
              <div className="zip-kv"><span>Tempat / Link</span><b>{selected.place || "-"}</b></div>
              <div className="zip-kv"><span>Status</span><b>{selected.status === "done" ? "Selesai" : selected.status === "cancelled" ? "Dibatalkan" : "Terjadwal"}</b></div>
              <div className="zip-kv"><span>Hasil</span><b>{selected.result === "passed" ? "Lulus" : selected.result === "failed" ? "Tidak lulus" : "-"}</b></div>
              <div className="zip-kv"><span>Lamaran</span><b>{appOfSelected ? statusLamaran(appOfSelected.status) : "—"}</b></div>
              {selected.note && (
                <div className="zip-note"><i className="fa-solid fa-note-sticky"></i> <strong>Catatan:</strong> {selected.note}</div>
              )}
              {aksiError && <div className="zip-error" style={{ marginTop: 12 }}>{aksiError}</div>}
              {aksiMsg && <div className="zip-success" style={{ marginTop: 12 }}>{aksiMsg}</div>}
              <div className="zip-actions" style={{ marginTop: 14 }}>
                <button type="button" className="zip-btn-success" disabled={memutuskan} onClick={() => putuskan("passed")}>Lulus & Terima</button>
                <button type="button" className="zip-btn-danger" disabled={memutuskan} onClick={() => putuskan("failed")}>Tidak Lulus</button>
                <button type="button" className="zip-btn-outline" onClick={() => jadwalUlang(selected)}>Jadwal Ulang</button>
              </div>
            </>
          )}
        </div>
      </div>

      <h3 className="zip-card-title">{editingId ? "Jadwal Ulang Interview" : "Jadwalkan Interview Baru"}</h3>
      <div className="zip-card" style={{ maxWidth: "none" }}>
        {formError && <div className="zip-error">{formError}</div>}
        {formOk && <div className="zip-success">{formOk}</div>}
        <div className="zip-form-grid">
          <label className="zip-field"><span>Siswa / Lamaran</span>
            <select value={form.application_id} onChange={set("application_id")} disabled={!!editingId}>
              <option value="">— Pilih lamaran —</option>
              {apps.map((a) => (
                <option key={a.id} value={a.id}>{a.student?.name || "-"} • {a.position || a.division || "-"} ({a.status})</option>
              ))}
            </select>
          </label>
          <label className="zip-field"><span>Mode</span>
            <select value={form.mode} onChange={set("mode")}>
              <option value="offline">Offline (di lokasi)</option>
              <option value="online">Online (video call)</option>
            </select>
          </label>
          <label className="zip-field"><span>Tanggal</span><input type="date" value={form.date} onChange={set("date")} /></label>
          <label className="zip-field"><span>Jam</span><input type="time" value={form.time} onChange={set("time")} /></label>
          <label className="zip-field"><span>Link meeting / lokasi</span><input value={form.link} onChange={set("link")} placeholder="Link / alamat" /></label>
          <label className="zip-field"><span>Pewawancara</span><input value={form.pewawancara} onChange={set("pewawancara")} placeholder="cth: H. Wijaya" /></label>
        </div>
        <div className="zip-form-actions">
          {editingId && <button type="button" className="zip-btn-outline" onClick={resetForm}>Batal Edit</button>}
          <button type="button" className="zip-btn-primary" disabled={saving} onClick={simpanJadwal}>
            {saving ? "Menyimpan…" : editingId ? "Perbarui Jadwal" : "Simpan Jadwal"}
          </button>
        </div>
      </div>
    </div>
  );
}

export default SeleksiInterview;
