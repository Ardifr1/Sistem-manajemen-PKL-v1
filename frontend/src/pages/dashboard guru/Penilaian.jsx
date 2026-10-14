import { useEffect, useState } from "react";
import { assessmentsApi } from "../../api/index.js";
import { getEnrichedPlacements } from "../../lib/role-data.js";
import { SkelCards } from "../../components/role/skeleton.jsx";
import "./guru-pages.css";

const ASPEK = [
  { key: "kedisiplinan", label: "Kedisiplinan" },
  { key: "kompetensi", label: "Kompetensi" },
  { key: "sikap", label: "Sikap" },
];

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
  const [scores, setScores] = useState({}); // { placementId: { kedisiplinan, kompetensi, sikap } }
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  useEffect(() => {
    onMeta?.({ title: "Penilaian", subtitle: "Penilaian detail siswa bimbingan • Periode PKL 2026" });
    (async () => {
      try {
        const [pRes, aRes] = await Promise.all([
          getEnrichedPlacements(),
          assessmentsApi.index().catch(() => ({ data: [] })),
        ]);
        setPlacements(pRes || []);
        // muat nilai yang sudah ada
        const existing = {};
        for (const a of (aRes?.data ?? [])) {
          if (a.assessor_role !== "teacher") continue;
          const pid = a.placement_id;
          if (!existing[pid]) existing[pid] = {};
          // map component/aspect name ke key
          const key = ASPEK.find((x) => a.component_name?.toLowerCase().includes(x.key))?.key
            || a.aspect?.toLowerCase();
          if (key && ASPEK.some((x) => x.key === key)) existing[pid][key] = a.score;
        }
        setScores(existing);
      } finally {
        setLoading(false);
      }
    })();
  }, [onMeta]);

  const setScore = (pid, cid, val) => {
    const num = val === "" ? "" : Math.max(0, Math.min(100, Number(val) || 0));
    setScores((s) => ({ ...s, [pid]: { ...(s[pid] || {}), [cid]: num } }));
  };

  const weighted = (pid) => {
    const sc = scores[pid] || {};
    const vals = ASPEK.map((a) => sc[a.key]).filter((v) => v !== "" && v != null && !isNaN(v));
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
    const gagal = [];
    try {
      for (const p of placements) {
        const sc = scores[p.id] || {};
        for (const a of ASPEK) {
          const val = sc[a.key];
          if (val === "" || val == null) continue;
          await assessmentsApi.store({
            placement_id: p.id,
            assessor_role: "teacher",
            aspect: a.key,
            component_name: a.label,
            score: Number(val),
          }).catch(() => {});
        }
      }
      setSuccess("Penilaian tersimpan sebagai draf.");
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
                  {ASPEK.map((a) => (
                    <th key={a.key} style={{ textAlign: "center" }}>{a.label}</th>
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
                    {ASPEK.map((a) => (
                      <td key={a.key} style={{ textAlign: "center" }}>
                        <input
                          type="number"
                          min="0"
                          max="100"
                          className="zip-score-input"
                          value={scores[p.id]?.[a.key] ?? ""}
                          onChange={(e) => setScore(p.id, a.key, e.target.value)}
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
                {ASPEK.map((a) => (
                  <label key={a.key} className="nilai-field">
                    <span>{a.label}</span>
                    <input
                      type="number"
                      min="0"
                      max="100"
                      className="zip-score-input"
                      value={scores[p.id]?.[a.key] ?? ""}
                      onChange={(e) => setScore(p.id, a.key, e.target.value)}
                      placeholder="0"
                    />
                  </label>
                ))}
              </div>
            </div>
          ))}
        </div>

        <div className="nilai-actions">
          <span className="zip-sub">Rata-rata dihitung otomatis dari 3 komponen.</span>
          <button type="submit" className="zip-btn-primary" disabled={saving || loading}>
            <i className="fa-solid fa-floppy-disk"></i> {saving ? "Menyimpan…" : "Simpan Penilaian"}
          </button>
        </div>
      </form>
    </div>
  );
}

export default Penilaian;
