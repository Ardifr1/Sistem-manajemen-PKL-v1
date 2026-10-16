import { useEffect, useState } from "react";
import { assessmentsApi, componentsApi } from "../../api/index.js";
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
 * Penilaian guru: mengisi komponen milik guru (assessor_role = 'teacher',
 * diatur admin), lalu sistem menghitung nilai akhir otomatis dari bobot.
 *
 * Catatan integrasi: penilaian dikirim ke backend sebagai
 * { placement_id, component_id, score } — bukan aspect/component_name bebas.
 * assessor_role & assessed_by dikunci oleh backend dari user yang login.
 */
function Penilaian({ onMeta }) {
  const [placements, setPlacements] = useState([]);
  const [components, setComponents] = useState([]);
  const [scores, setScores] = useState({}); // { "placementId:componentId": score }
  const [savedIds, setSavedIds] = useState({}); // { "placementId:componentId": assessmentId }
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  useEffect(() => {
    onMeta?.({ title: "Penilaian", subtitle: "Penilaian detail siswa bimbingan" });
    (async () => {
      try {
        const [pRes, cRes, aRes] = await Promise.all([
          getEnrichedPlacements(),
          componentsApi.index().catch(() => ({ data: [] })),
          assessmentsApi.index().catch(() => ({ data: [] })),
        ]);
        setPlacements(pRes || []);

        const guruComponents = (cRes?.data ?? []).filter(
          (c) => c.is_active !== false && (!c.assessor_role || c.assessor_role === "teacher")
        );
        setComponents(guruComponents);

        const ids = {};
        const vals = {};
        for (const a of (aRes?.data ?? [])) {
          if (a.assessor_role !== "teacher") continue;
          const key = `${a.placement_id}:${a.component_id}`;
          ids[key] = a.id;
          vals[key] = a.score;
        }
        setSavedIds(ids);
        setScores(vals);
      } finally {
        setLoading(false);
      }
    })();
  }, [onMeta]);

  const key = (pid, cid) => `${pid}:${cid}`;

  const setScore = (pid, cid, val) => {
    const num = val === "" ? "" : Math.max(0, Math.min(100, Number(val) || 0));
    setScores((s) => ({ ...s, [key(pid, cid)]: num }));
  };

  const rataRata = (pid) => {
    const vals = components
      .map((c) => scores[key(pid, c.id)])
      .filter((v) => v !== "" && v != null && !isNaN(v));
    if (!vals.length) return "-";
    return Math.round(vals.reduce((a, b) => a + Number(b), 0) / vals.length);
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
    try {
      for (const p of placements) {
        for (const c of components) {
          const val = scores[key(p.id, c.id)];
          if (val === "" || val == null) continue;
          const existingId = savedIds[key(p.id, c.id)];
          if (existingId) {
            await assessmentsApi.update(existingId, { score: Number(val) });
          } else {
            await assessmentsApi.store({
              placement_id: p.id,
              component_id: c.id,
              score: Number(val),
            });
          }
        }
      }
      setSuccess("Penilaian tersimpan sebagai draf.");
      // Muat ulang agar ID penilaian baru tersimpan (untuk update berikutnya).
      const aRes = await assessmentsApi.index().catch(() => ({ data: [] }));
      const ids = {};
      const vals = {};
      for (const a of (aRes?.data ?? [])) {
        if (a.assessor_role !== "teacher") continue;
        const k = `${a.placement_id}:${a.component_id}`;
        ids[k] = a.id;
        vals[k] = a.score;
      }
      setSavedIds(ids);
      setScores(vals);
    } catch (err) {
      setError(err?.message || "Gagal menyimpan penilaian.");
    } finally {
      setSaving(false);
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
                    <th key={c.id} style={{ textAlign: "center" }}>{c.name}</th>
                  ))}
                  <th style={{ textAlign: "center" }}>Rata-rata</th>
                </tr>
              </thead>
              <tbody>
                {placements.map((p) => (
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
                          value={scores[key(p.id, c.id)] ?? ""}
                          onChange={(e) => setScore(p.id, c.id, e.target.value)}
                          placeholder="0"
                        />
                      </td>
                    ))}
                    <td style={{ textAlign: "center", fontWeight: 800, fontSize: 15 }}>
                      {rataRata(p.id)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="m-cards nilai-cards">
          {placements.map((p) => (
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
                  <small>Rata-rata</small>
                  <b>{rataRata(p.id)}</b>
                </div>
              </div>
              <div className="nilai-inputs">
                {components.map((c) => (
                  <label key={c.id} className="nilai-field">
                    <span>{c.name}</span>
                    <input
                      type="number"
                      min="0"
                      max="100"
                      className="zip-score-input"
                      value={scores[key(p.id, c.id)] ?? ""}
                      onChange={(e) => setScore(p.id, c.id, e.target.value)}
                      placeholder="0"
                    />
                  </label>
                ))}
              </div>
            </div>
          ))}
        </div>

        <div className="nilai-actions">
          <span className="zip-sub">Rata-rata dihitung otomatis dari {components.length} komponen.</span>
          <button type="submit" className="zip-btn-primary" disabled={saving || loading}>
            <i className="fa-solid fa-floppy-disk"></i> {saving ? "Menyimpan…" : "Simpan Penilaian"}
          </button>
        </div>
      </form>
    </div>
  );
}

export default Penilaian;
