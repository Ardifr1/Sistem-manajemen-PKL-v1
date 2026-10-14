import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { journalsApi, assessmentsApi, finalAssessmentsApi, componentsApi } from "../../api/index.js";

function mingguKe(start, date) {
  const diff = (new Date(date) - new Date(start)) / 86400000;
  return Math.max(1, Math.floor(diff / 7) + 1);
}

function StatusPerkembangan({ onMeta, placement }) {
  const navigate = useNavigate();
  const [journals, setJournals] = useState([]);
  const [evals, setEvals] = useState([]);
  const [components, setComponents] = useState([]);
  const [finalScore, setFinalScore] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    onMeta?.({ title: "Status & Perkembangan", subtitle: "" });
    (async () => {
      try {
        const [jRes, aRes, cRes, fRes] = await Promise.all([
          journalsApi.index({ placement_id: placement?.id }).catch(() => ({ data: [] })),
          assessmentsApi.index({ placement_id: placement?.id }).catch(() => ({ data: [] })),
          componentsApi.index().catch(() => ({ data: [] })),
          finalAssessmentsApi.index({ placement_id: placement?.id }).catch(() => ({ data: [] })),
        ]);
        setJournals(jRes?.data ?? []);
        // Nilai komponen industri apa adanya (skor 0-100 dari backend).
        setEvals((aRes?.data ?? []).filter((a) => String(a.placement_id) === String(placement?.id)));
        setComponents(cRes?.data ?? []);
        // Siswa hanya melihat nilai akhir yang sudah dipublikasikan.
        const published = (fRes?.data ?? []).find(
          (f) => String(f.placement_id) === String(placement?.id) && f.status === "published"
        );
        setFinalScore(published ?? null);
      } finally { setLoading(false); }
    })();
  }, [onMeta, placement?.id]);

  const start = placement?.start_date || new Date().toISOString().slice(0, 10);
  const end = placement?.end_date || new Date().toISOString().slice(0, 10);
  const totalMinggu = Math.max(1, Math.round((new Date(end) - new Date(start)) / (86400000 * 7)));
  const mingguBerjalan = Math.min(totalMinggu, mingguKe(start, new Date().toISOString().slice(0, 10)));
  const persen = Math.round((mingguBerjalan / totalMinggu) * 100);

  const mingguTerisi = new Set(journals.map((j) => mingguKe(start, j.journal_date))).size;

  // konsistensi: 3 minggu terakhir terisi?
  const kini = mingguKe(start, new Date().toISOString().slice(0, 10));
  const konsisten = [kini, kini - 1, kini - 2].every((m) =>
    journals.some((j) => mingguKe(start, j.journal_date) === m)
  );

  const byComponent = new Map();
  for (const evaluation of evals) {
    if (!["company", "supervisor"].includes(evaluation.assessor_role)) continue;
    const scores = byComponent.get(evaluation.component_id) || [];
    scores.push(Number(evaluation.score));
    byComponent.set(evaluation.component_id, scores);
  }
  const kompetensiTampil = [...byComponent].map(([componentId, scores]) => ({
    label: components.find((component) => String(component.id) === String(componentId))?.name || `Komponen #${componentId}`,
    pct: Math.max(0, Math.min(100, Math.round(scores.reduce((sum, score) => sum + score, 0) / scores.length))),
  }));
  const nilaiTerkunci = !finalScore;

  return (
    <div className="siswa-status-grid">
      <div>
        <div className="siswa-card">
          <div className="siswa-between" style={{ marginBottom: 14 }}>
            <h3 className="siswa-card-title">Progres PKL</h3>
            <span className={`siswa-badge ${konsisten ? "s-badge-green" : "s-badge-amber"}`}>
              <span className="dot"></span>{konsisten ? "Baik" : "Perlu Ditingkatkan"}
            </span>
          </div>
          <div className="siswa-between" style={{ marginBottom: 4 }}>
            <b style={{ fontSize: 14, color: "#0f172a" }}>Minggu {mingguBerjalan} dari {totalMinggu}</b>
            <span className="siswa-muted">{persen}%</span>
          </div>
          <div className="siswa-segbar">
            {Array.from({ length: totalMinggu }).map((_, i) => (
              <i key={i} className={i < mingguBerjalan ? "on" : ""}></i>
            ))}
          </div>
          <p className="siswa-muted" style={{ margin: "4px 0 0" }}>
            Jurnal terisi {mingguTerisi} dari {totalMinggu} minggu
            {konsisten ? " • konsisten 3 minggu terakhir" : ""}
          </p>
        </div>

        <div className="siswa-card">
          <div className="siswa-between" style={{ marginBottom: 16 }}>
            <h3 className="siswa-card-title">Kompetensi</h3>
            <span className="siswa-muted" style={{ fontSize: 12 }}>Penilaian pembimbing industri</span>
          </div>
          {loading ? <p className="siswa-muted">Memuat…</p> : kompetensiTampil.length === 0 ? (
            <p className="siswa-muted">Belum ada penilaian pembimbing industri.</p>
          ) : kompetensiTampil.map((k) => (
            <div key={k.label} style={{ marginBottom: 16 }}>
              <div className="siswa-between" style={{ marginBottom: 6 }}>
                <b style={{ fontSize: 13.5, color: "#0f172a" }}>{k.label}</b>
                <span className="siswa-muted" style={{ fontWeight: 700 }}>{k.pct}%</span>
              </div>
              <div className="siswa-progress"><i style={{ width: `${k.pct}%` }}></i></div>
            </div>
          ))}
        </div>
      </div>

      <div>
        <div className="siswa-card" style={{ textAlign: "center" }}>
          <div className="siswa-between" style={{ marginBottom: 16, textAlign: "left" }}>
            <h3 className="siswa-card-title">Nilai Akhir</h3>
            <span className={`siswa-badge ${nilaiTerkunci ? "s-badge-gray" : "s-badge-green"}`}>
              <span className="dot"></span>{nilaiTerkunci ? "Terkunci" : "Dipublikasikan"}
            </span>
          </div>
          {nilaiTerkunci ? (
            <div style={{ padding: "12px 0 20px" }}>
              <span className="siswa-modal-ic" style={{ background: "#f1f5f9", color: "#94a3b8", margin: "0 auto 14px" }}>
                <i className="fa-solid fa-lock"></i>
              </span>
              <b style={{ display: "block", fontSize: 14, color: "#0f172a", marginBottom: 6 }}>Nilai akhir belum tersedia</b>
              <p className="siswa-muted" style={{ margin: 0 }}>Tampil setelah PKL dinyatakan selesai dan nilai dipublikasikan sekolah.</p>
            </div>
          ) : (
            <div style={{ padding: "12px 0 20px" }}>
              <div style={{ fontSize: 52, fontWeight: 800, color: "#0f172a" }}>{finalScore.final_score}</div>
              <p className="siswa-muted" style={{ margin: "4px 0 0" }}>Nilai akhir PKL — skala 0–100</p>
            </div>
          )}
        </div>

        <div className="siswa-card">
          <h3 className="siswa-card-title" style={{ marginBottom: 14 }}>Feedback Perusahaan</h3>
          <div className="siswa-banner gray">
            <span className="b-ic"><i className="fa-solid fa-circle-info"></i></span>
            <div>
              Dapat diisi setelah PKL selesai
              {placement?.end_date ? <> (<b>{new Date(placement.end_date).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" })}</b>)</> : ""}.
            </div>
          </div>
          <button type="button" className="siswa-btn siswa-btn-outline siswa-btn-block"
            disabled={placement?.status !== "completed"} onClick={() => navigate("/siswa/feedback")}>
            Isi Feedback
          </button>
        </div>
      </div>
    </div>
  );
}

export default StatusPerkembangan;
