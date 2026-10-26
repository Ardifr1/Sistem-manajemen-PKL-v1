import { useEffect, useState } from "react";
import { applicationsApi, interviewsApi, placementsApi, supervisorsApi } from "../../api/index.js";

const TABS = [
  { key: "all", label: "Semua" },
  { key: "pending", label: "Diajukan" },
  { key: "seleksi", label: "Seleksi" },
  { key: "interview", label: "Interview" },
  { key: "accepted", label: "Diterima" },
];

function PengajuanMagang({ onMeta }) {
  const [apps, setApps] = useState([]);
  const [placements, setPlacements] = useState([]);
  const [tab, setTab] = useState("all");
  const [loading, setLoading] = useState(true);
  const [assigning, setAssigning] = useState(null);
  const [assignError, setAssignError] = useState("");
  const [assignSaving, setAssignSaving] = useState(false);
  const [supervisors, setSupervisors] = useState([]);
  const [selSup, setSelSup] = useState(null);
  const [scheduling, setScheduling] = useState(null);
  const [schedForm, setSchedForm] = useState({ date: "", time: "", mode: "offline", place: "", note: "" });
  const [schedSaving, setSchedSaving] = useState(false);
  const [schedError, setSchedError] = useState("");
  const [viewIv, setViewIv] = useState(null);
  const [viewIvLoading, setViewIvLoading] = useState(false);

  const load = async () => {
    try {
      const [appRes, plRes] = await Promise.all([
        applicationsApi.index(),
        placementsApi.index().catch(() => ({ data: [] })),
      ]);
      setApps(Array.isArray(appRes) ? appRes : appRes?.data || []);
      setPlacements(Array.isArray(plRes) ? plRes : plRes?.data || []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    onMeta?.({ title: "Pengajuan Magang", subtitle: "Review lamaran • dikelompokkan per sekolah" });
    load();
  }, [onMeta]);

  const filtered = apps.filter((a) => tab === "all" || a.status === tab);

  const groups = {};
  filtered.forEach((a) => {
    const sch = a.student?.school?.name || a.school_name || "Sekolah";
    if (!groups[sch]) groups[sch] = [];
    groups[sch].push(a);
  });

  const counts = {
    all: apps.length,
    pending: apps.filter((a) => a.status === "pending").length,
    seleksi: apps.filter((a) => a.status === "seleksi").length,
    interview: apps.filter((a) => a.status === "interview").length,
    accepted: apps.filter((a) => a.status === "accepted").length,
  };

  const act = async (a, status) => {
    try {
      await applicationsApi.update(a.id, { status });
      load();
    } catch (e) {
      alert(e?.message || "Gagal memperbarui status.");
    }
  };

  // Penempatan resmi baru ada setelah siswa memilih tawaran yang diterima;
  // pembimbing industri ditugaskan pada penempatan tersebut (bukan lamaran).
  const placementFor = (a) =>
    placements.find((p) => String(p.application_id) === String(a.id)) || null;

  const openAssign = async (a) => {
    const placement = placementFor(a);
    if (!placement) {
      alert("Siswa belum memilih tawaran ini sebagai tujuan PKL, sehingga penempatan resmi belum tersedia.");
      return;
    }
    setAssigning({ app: a, placement });
    setSelSup(null);
    setAssignError("");
    try {
      const r = await supervisorsApi.index();
      setSupervisors(Array.isArray(r) ? r : r?.data || []);
    } catch {
      setSupervisors([]);
    }
  };

  const doAssign = async () => {
    if (!assigning || !selSup) return;
    setAssignSaving(true);
    setAssignError("");
    try {
      await placementsApi.assignSupervisor(assigning.placement.id, selSup);
      setAssigning("done");
      load();
    } catch (e) {
      setAssignError(e?.message || "Gagal menugaskan pembimbing.");
    } finally {
      setAssignSaving(false);
    }
  };

  const openSchedule = (a) => {
    setScheduling(a);
    setSchedForm({ date: "", time: "", mode: "offline", place: "", note: "" });
    setSchedError("");
  };

  const doSchedule = async () => {
    if (!scheduling) return;
    if (!schedForm.date || !schedForm.time) {
      setSchedError("Tanggal dan jam wajib diisi.");
      return;
    }
    setSchedSaving(true);
    setSchedError("");
    try {
      await interviewsApi.store({
        application_id: scheduling.id,
        scheduled_at: `${schedForm.date} ${schedForm.time}:00`,
        mode: schedForm.mode,
        place: schedForm.place,
        note: schedForm.note,
        status: "scheduled",
      });
      await applicationsApi.update(scheduling.id, { status: "interview" });
      setScheduling("done");
      load();
    } catch (e) {
      setSchedError(e?.message || "Gagal menyimpan jadwal interview.");
    } finally {
      setSchedSaving(false);
    }
  };

  const openViewIv = async (a) => {
    setViewIvLoading(true);
    setViewIv({ _loading: true, app: a });
    try {
      const r = await interviewsApi.index({ application_id: a.id });
      const rows = Array.isArray(r) ? r : r?.data || [];
      setViewIv(rows[0] ? { ...rows[0], app: a } : { _empty: true, app: a });
    } catch {
      setViewIv({ _empty: true, app: a });
    } finally {
      setViewIvLoading(false);
    }
  };

  const badge = (s) =>
    s === "accepted" ? "b-green" : s === "interview" ? "b-blue" : s === "seleksi" ? "b-purple" : s === "rejected" ? "b-red" : "b-amber";
  const badgeLabel = (s) =>
    s === "accepted" ? "Diterima" : s === "interview" ? "Interview" : s === "seleksi" ? "Dalam Seleksi" : s === "rejected" ? "Ditolak" : s === "pending" ? "Diajukan" : s;

  return (
    <div className="zip-page">
      <div className="zip-tabs">
        {TABS.map((t) => (
          <button key={t.key} type="button" className={`zip-tab${tab === t.key ? " active" : ""}`} onClick={() => setTab(t.key)}>
            {t.label} ({counts[t.key]})
          </button>
        ))}
      </div>
      {loading && <div className="zip-muted">Memuat…</div>}
      {Object.entries(groups).map(([sch, list]) => (
        <div key={sch} style={{ marginBottom: 18 }}>
          <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 10 }}>
            <i className="fa-solid fa-school"></i> {sch} <span className="zip-count">{list.length} pengajuan</span>
          </div>
          <div className="zip-grid2">
            {list.map((a) => (
              <div className="zip-app-card" key={a.id}>
                <span className={`zip-badge ${badge(a.status)}`} style={{ float: "right" }}>{badgeLabel(a.status)}</span>
                <div className="nm">{a.student?.name || "-"}</div>
                <div className="meta">{a.student?.class || ""} • Rata-rata {a.student?.gpa || "-"} • Lamar: {a.position || a.division || "-"}</div>
                {(a.student?.skills || []).length > 0 && (
                  <div style={{ marginBottom: 6 }}>{a.student.skills.map((s) => <span className="zip-tag" key={s}>{s}</span>)}</div>
                )}
                <div className="zip-actions">
                  {a.status === "pending" && (
                    <>
                      <button type="button" className="zip-btn-outline" onClick={() => act(a, "seleksi")}>Masuk Seleksi</button>
                      <button type="button" className="zip-btn-primary" onClick={() => openSchedule(a)}>Jadwalkan Interview</button>
                      <button type="button" className="zip-btn-success" onClick={() => act(a, "accepted")}>Terima</button>
                      <button type="button" className="zip-btn-danger" onClick={() => act(a, "rejected")}>Tolak</button>
                    </>
                  )}
                  {a.status === "seleksi" && (
                    <>
                      <button type="button" className="zip-btn-primary" onClick={() => openSchedule(a)}>Jadwalkan Interview</button>
                      <button type="button" className="zip-btn-success" onClick={() => act(a, "accepted")}>Terima</button>
                      <button type="button" className="zip-btn-danger" onClick={() => act(a, "rejected")}>Tolak</button>
                    </>
                  )}
                  {a.status === "interview" && (
                    <>
                      <button type="button" className="zip-btn-outline" onClick={() => openViewIv(a)}>Lihat Hasil Interview</button>
                      <button type="button" className="zip-btn-success" onClick={() => act(a, "accepted")}>Terima</button>
                      <button type="button" className="zip-btn-danger" onClick={() => act(a, "rejected")}>Tolak</button>
                    </>
                  )}
                  {(a.status === "accepted" || a.status === "approved") && (
                    placementFor(a) ? (
                      <button type="button" className="zip-btn-outline" onClick={() => openAssign(a)}>
                        {placementFor(a).supervisor_id ? "Ganti Pembimbing →" : "Tugaskan Pembimbing →"}
                      </button>
                    ) : (
                      <span className="zip-muted" style={{ fontSize: 12 }}>
                        Menunggu siswa memilih tujuan PKL
                      </span>
                    )
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      ))}
      {!loading && filtered.length === 0 && <div className="zip-muted">Belum ada pengajuan.</div>}

      {/* Modal tugaskan pembimbing */}
      {assigning && assigning !== "done" && (
        <div className="zip-modal-overlay" onClick={() => setAssigning(null)}>
          <div className="zip-modal" onClick={(e) => e.stopPropagation()}>
            <h2>Tugaskan Pembimbing</h2>
            <div className="sub">Pilih pembimbing industri untuk siswa yang diterima</div>
            <div className="zip-popt" style={{ cursor: "default", marginBottom: 18 }}>
              <div><div className="nm">{assigning.app.student?.name || "-"} <span style={{ color: "#94a3b8", fontWeight: 400 }}>({assigning.app.position || "-"})</span></div>
              <div style={{ fontSize: 12, color: "#94a3b8" }}>{assigning.app.student?.class || ""} • Diterima</div></div>
            </div>
            {assignError && <div className="zip-error">{assignError}</div>}
            <div className="zip-label" style={{ marginBottom: 8 }}>Pembimbing Industri</div>
            {supervisors.map((s) => (
              <div key={s.id} className={`zip-popt${selSup === s.id ? " sel" : ""}`} onClick={() => setSelSup(s.id)}>
                <div><div className="nm">{s.user?.name || s.name}</div><div style={{ fontSize: 12, color: "#94a3b8" }}>{s.position || s.jabatan || ""}</div></div>
                <span className="zip-count">{s.students_count ?? "?"} siswa</span>
              </div>
            ))}
            {supervisors.length === 0 && <div className="zip-muted">Belum ada pembimbing. Tambahkan di menu Pembimbing.</div>}
            <div className="zip-actions" style={{ justifyContent: "flex-end", marginTop: 20 }}>
              <button type="button" className="zip-btn-outline" onClick={() => setAssigning(null)}>Batal</button>
              <button type="button" className="zip-btn-primary" disabled={!selSup || assignSaving} onClick={doAssign}>
                {assignSaving ? "Menyimpan…" : "Tugaskan"}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal jadwalkan interview */}
      {scheduling && scheduling !== "done" && (
        <div className="zip-modal-overlay" onClick={() => setScheduling(null)}>
          <div className="zip-modal" onClick={(e) => e.stopPropagation()}>
            <h2>Jadwalkan Interview</h2>
            <div className="sub">{scheduling.student?.name || "-"} • {scheduling.position || scheduling.division || "-"}</div>
            {schedError && <div className="zip-error">{schedError}</div>}
            <div className="zip-form-grid">
              <label className="zip-field"><span>Tanggal</span><input type="date" value={schedForm.date} onChange={(e) => setSchedForm((f) => ({ ...f, date: e.target.value }))} /></label>
              <label className="zip-field"><span>Jam</span><input type="time" value={schedForm.time} onChange={(e) => setSchedForm((f) => ({ ...f, time: e.target.value }))} /></label>
              <label className="zip-field"><span>Mode</span>
                <select value={schedForm.mode} onChange={(e) => setSchedForm((f) => ({ ...f, mode: e.target.value }))}>
                  <option value="offline">Offline (di lokasi)</option>
                  <option value="online">Online (video call)</option>
                </select>
              </label>
              <label className="zip-field"><span>Tempat / Link</span><input value={schedForm.place} onChange={(e) => setSchedForm((f) => ({ ...f, place: e.target.value }))} placeholder="Ruang meeting / link meet" /></label>
              <label className="zip-field" style={{ gridColumn: "1 / -1" }}><span>Catatan (opsional)</span><input value={schedForm.note} onChange={(e) => setSchedForm((f) => ({ ...f, note: e.target.value }))} placeholder="cth: bawa CV cetak" /></label>
            </div>
            <div className="zip-actions" style={{ justifyContent: "flex-end", marginTop: 20 }}>
              <button type="button" className="zip-btn-outline" onClick={() => setScheduling(null)}>Batal</button>
              <button type="button" className="zip-btn-primary" disabled={schedSaving} onClick={doSchedule}>
                {schedSaving ? "Menyimpan…" : "Simpan Jadwal"}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Sukses jadwal */}
      {scheduling === "done" && (
        <div className="zip-modal-overlay" onClick={() => setScheduling(null)}>
          <div className="zip-modal" style={{ textAlign: "center" }} onClick={(e) => e.stopPropagation()}>
            <div style={{ width: 72, height: 72, borderRadius: "50%", background: "#dbeafe", display: "flex", alignItems: "center", justifyContent: "center", margin: "0 auto 16px" }}>
              <i className="fa-solid fa-calendar-check" style={{ fontSize: 30, color: "#1d4ed8" }}></i>
            </div>
            <h2>Interview Terjadwal!</h2>
            <div className="sub">Status lamaran otomatis menjadi "Interview". Kelola jadwal di menu Seleksi & Interview.</div>
            <div className="zip-actions" style={{ justifyContent: "center" }}>
              <button type="button" className="zip-btn-primary" onClick={() => { setScheduling(null); load(); }}>Kembali ke Daftar</button>
            </div>
          </div>
        </div>
      )}

      {/* Modal lihat hasil interview */}
      {viewIv && (
        <div className="zip-modal-overlay" onClick={() => setViewIv(null)}>
          <div className="zip-modal" onClick={(e) => e.stopPropagation()}>
            <h2>Hasil Interview</h2>
            <div className="sub">{viewIv.app?.student?.name || "-"}</div>
            {viewIvLoading || viewIv._loading ? (
              <div className="zip-muted">Memuat…</div>
            ) : viewIv._empty ? (
              <div className="zip-muted">Belum ada jadwal interview untuk lamaran ini.</div>
            ) : (
              <div>
                <div className="zip-kv"><span>Jadwal</span><b>{String(viewIv.scheduled_at || "-").slice(0, 16).replace("T", " ")}</b></div>
                <div className="zip-kv"><span>Mode</span><b>{viewIv.mode === "online" ? "Online" : "Offline"}</b></div>
                <div className="zip-kv"><span>Tempat / Link</span><b>{viewIv.place || "-"}</b></div>
                <div className="zip-kv"><span>Status</span><b>{viewIv.status === "done" ? "Selesai" : viewIv.status === "cancelled" ? "Dibatalkan" : "Terjadwal"}</b></div>
                <div className="zip-kv"><span>Hasil</span><b>{viewIv.result === "passed" ? "Lulus" : viewIv.result === "failed" ? "Tidak lulus" : "-"}</b></div>
                {viewIv.note && <div className="zip-note"><i className="fa-solid fa-note-sticky"></i> <strong>Catatan:</strong> {viewIv.note}</div>}
              </div>
            )}
            <div className="zip-actions" style={{ justifyContent: "flex-end", marginTop: 20 }}>
              <button type="button" className="zip-btn-outline" onClick={() => setViewIv(null)}>Tutup</button>
            </div>
          </div>
        </div>
      )}

      {/* Sukses */}
      {assigning === "done" && (
        <div className="zip-modal-overlay" onClick={() => setAssigning(null)}>
          <div className="zip-modal" style={{ textAlign: "center" }} onClick={(e) => e.stopPropagation()}>
            <div style={{ width: 72, height: 72, borderRadius: "50%", background: "#dcfce7", display: "flex", alignItems: "center", justifyContent: "center", margin: "0 auto 16px" }}>
              <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
            </div>
            <h2>Berhasil Ditugaskan!</h2>
            <div className="sub">Pembimbing dapat langsung memverifikasi jurnal & memberi evaluasi.</div>
            <div className="zip-actions" style={{ justifyContent: "center" }}>
              <button type="button" className="zip-btn-primary" onClick={() => { setAssigning(null); load(); }}>Kembali ke Daftar</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default PengajuanMagang;
