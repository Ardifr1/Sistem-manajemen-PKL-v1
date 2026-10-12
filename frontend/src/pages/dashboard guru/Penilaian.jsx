import { useEffect, useState } from "react";
import { assessmentsApi, componentsApi, finalAssessmentsApi } from "../../api/index.js";
import { getEnrichedPlacements } from "../../lib/role-data.js";
import { SkelCards } from "../../components/role/skeleton.jsx";
import "./guru-pages.css";

function initials(name) {
  const parts = String(name || "?").trim().split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

const AVATAR_COLORS = ["#2563eb", "#16a34a", "#dc2626", "#7c3aed", "#d97706", "#0ea5e9"];
function avatarColor(name) {
  let h = 0;
  for (const c of String(name || "?")) h = (h * 31 + c.charCodeAt(0)) % 997;
  return AVATAR_COLORS[h % AVATAR_COLORS.length];
}

/**
 * Penilaian guru: mengisi komponen milik guru (diatur admin),
 * lalu sistem menghitung nilai akhir otomatis dari bobot.
 */
function Penilaian({ onMeta }) {
  const [placements, setPlacements] = useState([]);
  const [components, setComponents] = useState([]);
  const [scores, setScores] = useState({}); // { placementId: { componentId: nilai } }
  const [savedIds, setSavedIds] = useState({}); // { placementId: { componentId: assessmentId } }
  const [finals, setFinals] = useState({}); // { placementId: finalAssessment }
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [calcId, setCalcId] = useState(null);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const load = async () => {
    try {
      const [pRes, cRes, aRes, fRes] = await Promise.all([
        getEnrichedPlacements(),
        componentsApi.index().catch(() => ({ data: [] })),
        assessmentsApi.index().catch(() => ({ data: [] })),
        finalAssessmentsApi.index().catch(() => ({ data: [] })),
      ]);
      setPlacements(pRes || []);
      const comps = (cRes?.data ?? []).filter(
        (c) => c.is_active !== false && (!c.assessor_role || c.assessor_role === "teacher")
      );
      setComponents(comps);
      const existing = {};
      const ids = {};
      for (const a of (aRes?.data ?? [])) {
        if (a.assessor_role !== "teacher") continue;
        const pid = a.placement_id;
        if (!existing[pid]) existing[pid] = {};
        if (!ids[pid]) ids[pid] = {};
        existing[pid][a.component_id] = a.score;
        ids[pid][a.component_id] = a.id;
      }
      setScores(existing);
      setSavedIds(ids);
      const fm = {};
      for (const f of (fRes?.data ?? [])) fm[f.placement_id] = f;
      setFinals(fm);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    onMeta?.({ title: "Penilaian", subtitle: "Nilai komponen guru • dihitung otomatis dari bobot admin" });
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [onMeta]);

  const setScore = (pid, cid, val) => {
    const num = val === "" ? "" : Math.max(0, Math.min(100, Number(val) || 0));
    setScores((s) => ({ ...s, [pid]: { ...(s[pid] || {}), [cid]: num } }));
  };

  const weighted = (pid) => {
    const sc = scores[pid] || {};
    let ws = 0, wsum = 0, filled = 0;
    for (const c of components) {
      const v = sc[c.id];
      if (v === "" || v == null || isNaN(v)) continue;
      const w = Number(c.weight) || 0;
      ws += Number(v) * w;
      wsum += w;
      filled += 1;
    }
    if (!filled) return { text: "-", done: false };
    return { text: String(Math.round((ws / (wsum || 1)) * 100) / 100), done: filled === components.length, filled, total: components.length };
  };

  const submit = async (e) => {
    e.preventDefault();
    setError("");
    setSuccess("");
    if (!components.length) {
      setError("Belum ada komponen penilaian guru yang diatur admin.");
      return;
    }
    setSaving(true);
    const gagal = [];
    try {
      for (const p of placements) {
        const sc = scores[p.id] || {};
        const idMap = savedIds[p.id] || {};
        for (const c of components) {
          const val = sc[c.id];
          if (val === "" || val == null) continue;
          try {
            if (idMap[c.id]) {
              await assessmentsApi.update(idMap[c.id], { score: Number(val) });
            } else {
              await assessmentsApi.store({
                placement_id: p.id,
                component_id: c.id,
                score: Number(val),
              });
            }
          } catch (err) {
            gagal.push(`${p.student?.name || "Siswa"} / ${c.name}: ${err?.message || "gagal"}`);
          }
        }
      }
      if (gagal.length) {
        setError(`Sebagian gagal tersimpan:\n${gagal.slice(0, 5).join("\n")}${gagal.length > 5 ? `\n…dan ${gagal.length - 5} lainnya` : ""}`);
      } else {
        setSuccess("Penilaian guru tersimpan.");
      }
      await load();
    } finally {
      setSaving(false);
    }
  };

  const hitung = async (pid) => {
    setError("");
    setSuccess("");
    setCalcId(pid);
    try {
      const res = await finalAssessmentsApi.calculate(pid);
      const fa = res?.data?.final_assessment;
      if (fa) setFinals((f) => ({ ...f, [pid]: fa }));
      setSuccess(`Nilai akhir terhitung: ${res?.data?.final_score ?? "-"}. Status: draf (belum dipublikasikan).`);
    } catch (err) {
      const missing = err?.data?.missing || err?.missing;
      setError(missing?.length
        ? `Belum bisa dihitung. Komponen yang belum dinilai: ${missing.join(", ")}.`
        : (err?.message || "Gagal menghitung nilai akhir."));
    } finally {
      setCalcId(null);
    }
  };

  return (
    <div className="zip-page has-m-cards">
      <div className="zip-info-banner">
        <span className="zip-info-icon"><i className="fa-solid fa-circle-info"></i></span>
        <div>
          <strong>Komponen & bobot diatur admin.</strong> Isi nilai 0–100 per komponen,
          simpan, lalu <strong>Hitung Nilai Akhir</strong> (otomatis dari bobot).
          Publikasi nilai ke siswa dilakukan oleh admin setelah PKL selesai.
        </div>
      </div>

      <form className="zip-card" style={{ maxWidth: "none" }} onSubmit={submit}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 4 }}>
          <div>
            <h3 className="zip-card-title" style={{ margin: 0 }}>Form Penilaian Guru</h3>
            <div className="zip-sub">Skala 0–100 • {components.length} komponen aktif</div>
          </div>
        </div>

        {error && <div className="zip-error" style={{ whiteSpace: "pre-line" }}>{error}</div>}
        {success && <div className="zip-success">{success}</div>}

        {loading ? (
          <SkelCards n={2} />
        ) : placements.length === 0 ? (
          <p className="zip-muted">Belum ada siswa bimbingan.</p>
        ) : components.length === 0 ? (
          <p className="zip-muted">Belum ada komponen penilaian guru. Minta admin mengatur di menu Komponen Penilaian.</p>
        ) : (
          <div className="zip-table-wrap" style={{ boxShadow: "none", marginTop: 12 }}>
            <table className="zip-table">
              <thead>
                <tr>
                  <th>Siswa</th>
                  {components.map((c) => (
                    <th key={c.id} style={{ textAlign: "center" }}>{c.name}<br /><small className="zip-muted">bobot {c.weight}</small></th>
                  ))}
                  <th style={{ textAlign: "center" }}>Tertimbang</th>
                  <th style={{ textAlign: "center" }}>Nilai Akhir</th>
                </tr>
              </thead>
              <tbody>
                {placements.map((p) => {
                  const w = weighted(p.id);
                  const fa = finals[p.id];
                  return (
                    <tr key={p.id}>
                      <td>
                        <span className="zip-user-cell">
                          <span className="zip-avatar" style={{ background: avatarColor(p.student?.name) }}>
                            {initials(p.student?.name)}
                          </span>
                          <span>
                            <div className="zip-user-name">{p.student?.name}</div>
                            <div className="zip-user-email">{p.student?.kelas || ""}</div>
                          </span>
                        </span>
                      </td>
                      {components.map((c) => (
                        <td key={c.id} style={{ textAlign: "center" }}>
                          <input
                            type="number"
                            min="0"
                            max="100"
                            className="zip-score-input"
                            value={scores[p.id]?.[c.id] ?? ""}
                            onChange={(e) => setScore(p.id, c.id, e.target.value)}
                            placeholder="0"
                          />
                        </td>
                      ))}
                      <td style={{ textAlign: "center", fontWeight: 800, fontSize: 15 }}>
                        {w.text}
                        {w.done === false && w.filled ? <small className="zip-muted"> ({w.filled}/{w.total})</small> : null}
                      </td>
                      <td style={{ textAlign: "center", whiteSpace: "nowrap" }}>
                        {fa ? (
                          <span className={`zip-badge ${fa.status === "published" ? "b-green" : "b-amber"}`}>
                            {fa.final_score} • {fa.status === "published" ? "Terbit" : "Draf"}
                          </span>
                        ) : (
                          <span className="zip-muted">—</span>
                        )}
                        <div style={{ marginTop: 6, display: "flex", gap: 6, justifyContent: "center" }}>
                          <button
                            type="button"
                            className="zip-btn-outline"
                            style={{ padding: "6px 12px", fontSize: 12 }}
                            disabled={calcId === p.id}
                            onClick={() => hitung(p.id)}
                          >
                            {calcId === p.id ? "Menghitung…" : "Hitung"}
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        <div className="m-cards nilai-cards">
          {placements.map((p) => {
            const w = weighted(p.id);
            const fa = finals[p.id];
            return (
              <div className="m-card" key={p.id}>
                <div className="m-card-top">
                  <span className="zip-avatar" style={{ background: avatarColor(p.student?.name), width: 44, height: 44, fontSize: 16 }}>
                    {initials(p.student?.name)}
                  </span>
                  <div className="m-card-tx">
                    <b>{p.student?.name}</b>
                    <small>{p.student?.kelas || ""}</small>
                  </div>
                  <div className="nilai-rata">
                    <small>Tertimbang</small>
                    <b>{w.text}</b>
                  </div>
                </div>
                <div className="nilai-inputs">
                  {components.map((c) => (
                    <label key={c.id} className="nilai-field">
                      <span>{c.name} <small>(bobot {c.weight})</small></span>
                      <input
                        type="number"
                        min="0"
                        max="100"
                        className="zip-score-input"
                        value={scores[p.id]?.[c.id] ?? ""}
                        onChange={(e) => setScore(p.id, c.id, e.target.value)}
                        placeholder="0"
                      />
                    </label>
                  ))}
                </div>
                <div className="m-card-actions" style={{ marginTop: 10 }}>
                  {fa && (
                    <span className={`zip-badge ${fa.status === "published" ? "b-green" : "b-amber"}`}>
                      {fa.final_score} • {fa.status === "published" ? "Terbit" : "Draf"}
                    </span>
                  )}
                  <button type="button" className="zip-btn-outline" disabled={calcId === p.id} onClick={() => hitung(p.id)}>
                    {calcId === p.id ? "Menghitung…" : "Hitung Nilai Akhir"}
                  </button>
                </div>
              </div>
            );
          })}
        </div>

        <div className="nilai-actions">
          <span className="zip-sub">Nilai tertimbang dihitung otomatis dari bobot tiap komponen.</span>
          <button type="submit" className="zip-btn-primary" disabled={saving || loading}>
            <i className="fa-solid fa-floppy-disk"></i> {saving ? "Menyimpan…" : "Simpan Penilaian"}
          </button>
        </div>
      </form>
    </div>
  );
}

export default Penilaian;
