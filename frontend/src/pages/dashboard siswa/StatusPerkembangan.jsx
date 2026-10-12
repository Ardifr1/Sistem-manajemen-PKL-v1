import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { journalsApi, assessmentsApi, finalAssessmentsApi } from "../../api/index.js";

function mingguKe(start, date) {
  const diff = (new Date(date) - new Date(start)) / 86400000;
  return Math.max(1, Math.floor(diff / 7) + 1);
}

function StatusPerkembangan({ onMeta, placement }) {
  const navigate = useNavigate();
  const [journals, setJournals] = useState([]);
  const [evals, setEvals] = useState([]);
  const [nilaiAkhir, setNilaiAkhir] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    onMeta?.({ title: "Status & Perkembangan", subtitle: "" });
    (async () => {
      try {
        const [jRes, aRes, fRes] = await Promise.all([
          journalsApi.index({ placement_id: placement?.id }).catch(() => ({ data: [] })),
          assessmentsApi.index().catch(() => ({ data: [] })),
          placement?.id ? finalAssessmentsApi.index({ placement_id: placement.id }).catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
        ]);
        setJournals(jRes?.data ?? []);
        setEvals((aRes?.data ?? []).filter((a) => String(a.placement_id) === String(placement?.id) && a.assessor_role === "supervisor"));
        const published = (fRes?.data ?? []).find((f) => f.status === "published");
        setNilaiAkhir(published || null);
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

  // kompetensi dari evaluasi pembimbing industri (skala 0-100 → %)
  const kompetensi = evals.length ? (() => {
    const byComp = {};
    for (const e of evals) {
      const label = e.component_name || e.component?.name || "Komponen";
      (byComp[label] = byComp[label] || []).push(Number(e.score));
    }
    return Object.entries(byComp).map(([label, vals]) => {
      const rata = vals.reduce((a, b) => a + b, 0) / vals.length;
      return { label, pct: Math.max(0, Math.min(100, Math.round(rata))) };
    });
  })() : [];

  const kompetensiTampil = kompetensi;

  const nilaiTerkunci = placement?.status !== "completed";

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
            {nilaiAkhir ? (
              <span className="siswa-badge s-badge-green"><span className="dot"></span>Dipublikasikan</span>
            ) : (
              <span className="siswa-badge s-badge-gray"><span className="dot"></span>Terkunci</span>
            )}
          </div>
          {nilaiAkhir ? (
            <div style={{ padding: "12px 0 20px" }}>
              <div style={{ fontSize: 52, fontWeight: 800, color: "#16a34a", lineHeight: 1 }}>
                {nilaiAkhir.final_score}
              </div>
              <p className="siswa-muted" style={{ margin: "8px 0 0" }}>Skala 0–100 • dihitung otomatis dari bobot komponen</p>
            </div>
          ) : (
            <div style={{ padding: "12px 0 20px" }}>
              <span className="siswa-modal-ic" style={{ background: "#f1f5f9", color: "#94a3b8", margin: "0 auto 14px" }}>
                <i className="fa-solid fa-lock"></i>
              </span>
              <b style={{ display: "block", fontSize: 14, color: "#0f172a", marginBottom: 6 }}>Nilai akhir belum tersedia</b>
              <p className="siswa-muted" style={{ margin: 0 }}>Tampil setelah PKL selesai dan nilai dipublikasikan guru.</p>
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
            disabled={nilaiTerkunci} onClick={() => navigate("/siswa/feedback")}>
            Isi Feedback
          </button>
        </div>
      </div>
    </div>
  );
}

export default StatusPerkembangan;
