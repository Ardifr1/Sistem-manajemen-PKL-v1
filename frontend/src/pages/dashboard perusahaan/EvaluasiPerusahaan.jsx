import { useEffect, useState } from "react";
import { placementsApi, assessmentsApi, componentsApi } from "../../api/index.js";

/* Evaluasi perusahaan — komponen penilaian milik industri (owner_type=industri).
 * Skala 1-4 dikonversi ke 0-100 dengan benar: score = nilai * 25.
 */

const SKALA = [
  { nilai: 4, label: "Sangat Baik" },
  { nilai: 3, label: "Baik" },
  { nilai: 2, label: "Cukup" },
  { nilai: 1, label: "Perlu Ditingkatkan" },
];

const toScore100 = (skala14) => Number(skala14) * 25;

function EvaluasiPerusahaan({ onMeta }) {
  const [rows, setRows] = useState([]);
  const [aspek, setAspek] = useState([]); // komponen industri aktif dari backend
  const [loading, setLoading] = useState(true);
  const [placementId, setPlacementId] = useState("");
  const [scores, setScores] = useState({});
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState({ t: "", ok: true });

  const load = async () => {
    try {
      const [r, cRes] = await Promise.all([
        placementsApi.index(),
        componentsApi.index().catch(() => ({ data: [] })),
      ]);
      setRows((Array.isArray(r) ? r : r?.data || []).filter((p) => p.status === "active" || p.status === "completed"));
      // Hanya komponen milik industri yang boleh dinilai perusahaan.
      const active = (cRes?.data ?? []).filter(
        (c) => c.is_active !== false && (!c.owner_type || c.owner_type === "industri")
      );
      setAspek(active.map((c) => ({
        id: c.id,
        key: c.id,
        label: c.name,
        desc: c.description || "",
        weight: c.weight,
      })));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    onMeta?.({ title: "Evaluasi", subtitle: "Nilai siswa magang per aspek (skala 1-4)" });
    load();
  }, [onMeta]);

  const setScore = (key, nilai) => setScores((s) => ({ ...s, [key]: nilai }));

  const submit = async (e) => {
    e.preventDefault();
    if (!placementId) { setMsg({ t: "Pilih siswa dulu.", ok: false }); return; }
    const kosong = aspek.filter((a) => !scores[a.key]);
    if (kosong.length) { setMsg({ t: `Masih ada ${kosong.length} aspek belum dinilai.`, ok: false }); return; }
    setSaving(true);
    setMsg({ t: "", ok: true });
    try {
      for (const [i, a] of aspek.entries()) {
        await assessmentsApi.store({
          placement_id: Number(placementId),
          component_id: a.id,
          // Konversi skala 1-4 → 0-100 (1→25, 2→50, 3→75, 4→100).
          score: toScore100(scores[a.key]),
          note: i === 0 ? (note.trim() || undefined) : undefined,
        });
      }
      setMsg({ t: "Evaluasi tersimpan.", ok: true });
      setPlacementId("");
      setScores({});
      setNote("");
    } catch (err) {
      setMsg({ t: err?.message || "Gagal menyimpan evaluasi.", ok: false });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="zip-page">
      <div className="zip-card" style={{ maxWidth: "none", marginBottom: 18 }}>
        <h3 className="zip-card-title" style={{ marginTop: 0 }}>Evaluasi Siswa PKL</h3>
        <p className="zip-sub" style={{ marginBottom: 16 }}>
          Nilai setiap aspek dengan skala 1-4. Sistem mengkonversi otomatis ke 0-100 (1→25, 2→50, 3→75, 4→100).
        </p>
        {msg.t && <div className={msg.ok ? "zip-success" : "zip-error"}>{msg.t}</div>}
        {!loading && aspek.length === 0 && (
          <div className="zip-warn">Belum ada komponen penilaian industri yang aktif. Hubungi admin.</div>
        )}
        <form onSubmit={submit}>
          <label className="zip-field" style={{ marginBottom: 20 }}>
            <span>Siswa</span>
            <select value={placementId} onChange={(e) => setPlacementId(e.target.value)}>
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

          {aspek.map((a) => (
            <div key={a.key} style={{ marginBottom: 18, paddingBottom: 18, borderBottom: "1px solid #f1f5f9" }}>
              <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 2 }}>{a.label}</div>
              <div className="zip-sub" style={{ marginBottom: 10 }}>{a.desc}</div>
              <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                {SKALA.map((s) => (
                  <label
                    key={s.nilai}
                    style={{
                      display: "flex", alignItems: "center", gap: 8,
                      padding: "9px 16px", borderRadius: 999,
                      border: scores[a.key] === s.nilai ? "2px solid #2563eb" : "1px solid #e2e8f0",
                      background: scores[a.key] === s.nilai ? "#eff6ff" : "#fff",
                      cursor: "pointer", fontSize: 13, fontWeight: 600,
                      color: scores[a.key] === s.nilai ? "#1d4ed8" : "#475569",
                    }}
                  >
                    <input
                      type="radio"
                      name={`aspek-${a.key}`}
                      checked={scores[a.key] === s.nilai}
                      onChange={() => setScore(a.key, s.nilai)}
                      style={{ accentColor: "#2563eb" }}
                    />
                    {s.nilai} — {s.label}
                  </label>
                ))}
              </div>
            </div>
          ))}

          <label className="zip-field">
            <span>Catatan pembimbing</span>
            <textarea rows="3" value={note} onChange={(e) => setNote(e.target.value)}
              placeholder="Tuliskan kelebihan siswa dan hal yang perlu ditingkatkan…" />
          </label>
          <div className="zip-form-actions">
            <button type="submit" className="zip-btn-primary" disabled={saving || aspek.length === 0}>
              {saving ? "Menyimpan…" : "Simpan Evaluasi"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default EvaluasiPerusahaan;
