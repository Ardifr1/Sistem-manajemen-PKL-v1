import { useEffect, useState } from "react";
import { placementsApi, assessmentsApi, componentsApi } from "../../api/index.js";
import { SkelCards } from "../../components/role/skeleton.jsx";

/* Evaluasi perusahaan — LIHAT hasil evaluasi (PRD §4.4).
 * Penilaian diisi oleh Pembimbing Industri & Guru; perusahaan hanya memantau. */

function peranLabel(role) {
  if (role === "supervisor") return "Pembimbing Industri";
  if (role === "teacher") return "Guru Pembimbing";
  return role || "-";
}

function EvaluasiPerusahaan({ onMeta }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [placementId, setPlacementId] = useState("");
  const [evals, setEvals] = useState([]);
  const [loadingEval, setLoadingEval] = useState(false);

  const load = async () => {
    try {
      const r = await placementsApi.index();
      setRows((Array.isArray(r) ? r : r?.data || []).filter((p) => p.status === "active" || p.status === "completed"));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    onMeta?.({ title: "Evaluasi", subtitle: "Hasil penilaian pembimbing industri & guru (read-only)" });
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [onMeta]);

  const pilih = async (id) => {
    setPlacementId(id);
    if (!id) { setEvals([]); return; }
    setLoadingEval(true);
    try {
      const [aRes, cRes] = await Promise.all([
        assessmentsApi.index({ placement_id: Number(id) }).catch(() => ({ data: [] })),
        componentsApi.index().catch(() => ({ data: [] })),
      ]);
      const comps = {};
      for (const c of (cRes?.data ?? [])) comps[c.id] = c;
      setEvals((aRes?.data ?? []).map((a) => ({ ...a, component_name: comps[a.component_id]?.name || a.component_name || "-" })));
    } finally {
      setLoadingEval(false);
    }
  };

  const siswa = rows.find((p) => String(p.id) === String(placementId));

  return (
    <div className="zip-page">
      <div className="zip-card" style={{ maxWidth: "none", marginBottom: 18 }}>
        <h3 className="zip-card-title" style={{ marginTop: 0 }}>Hasil Evaluasi Siswa PKL</h3>
        <p className="zip-sub" style={{ marginBottom: 16 }}>
          Penilaian diisi oleh <b>Pembimbing Industri</b> dan <b>Guru Pembimbing</b> sesuai
          komponen yang diatur admin. Perusahaan memantau hasilnya di sini.
        </p>
        <label className="zip-field" style={{ marginBottom: 0 }}>
          <span>Siswa</span>
          <select value={placementId} onChange={(e) => pilih(e.target.value)}>
            <option value="">— Pilih siswa —</option>
            {Object.entries(
              rows.reduce((g, p) => {
                const sch = p.student?.school?.name || "Sekolah";
                (g[sch] = g[sch] || []).push(p);
                return g;
              }, {})
            ).map(([sch, list]) => (
              <optgroup key={sch} label={sch}>
                {list.map((p) => (
                  <option key={p.id} value={p.id}>{p.student?.name || `#${p.id}`}</option>
                ))}
              </optgroup>
            ))}
          </select>
        </label>
      </div>

      {placementId && (
        <div className="zip-card" style={{ maxWidth: "none" }}>
          <h3 className="zip-card-title" style={{ marginTop: 0 }}>
            {siswa?.student?.name || "Siswa"}
          </h3>
          {loadingEval ? <SkelCards n={2} /> : evals.length === 0 ? (
            <p className="zip-muted">Belum ada penilaian untuk siswa ini.</p>
          ) : (
            <div className="zip-table-wrap">
              <table className="zip-table">
                <thead><tr><th>Komponen</th><th>Penilai</th><th style={{ textAlign: "center" }}>Nilai</th><th>Catatan</th></tr></thead>
                <tbody>
                  {evals.map((a) => (
                    <tr key={a.id}>
                      <td><b>{a.component_name}</b></td>
                      <td><span className="zip-badge b-blue">{peranLabel(a.assessor_role)}</span></td>
                      <td style={{ textAlign: "center", fontWeight: 800, fontSize: 15 }}>{a.score}</td>
                      <td className="zip-muted">{a.note || "-"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {!placementId && !loading && rows.length === 0 && (
        <p className="zip-muted">Belum ada siswa PKL di perusahaan.</p>
      )}
    </div>
  );
}

export default EvaluasiPerusahaan;
