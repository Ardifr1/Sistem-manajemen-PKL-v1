import { useEffect, useState } from "react";
import { assessmentsApi, componentsApi, finalsApi } from "../../api/index.js";
import { getEnrichedPlacements } from "../../lib/role-data.js";
import { SkelCards } from "../../components/role/skeleton.jsx";
import "./guru-pages.css";

/**
 * Komponen penilaian diambil dari backend (assessment_components) — guru
 * menilai tiap komponen aktif dengan component_id, bukan aspek hard-coded.
 */
const ASPEK_FALLBACK = [
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
 * Penilaian guru ala referensi: tabel inline 3 aspek + rata-rata otomatis.
 * Skala 0-100. Tersimpan sebagai draf.
 */
function Penilaian({ onMeta }) {
  const [placements, setPlacements] = useState([]);
  const [aspek, setAspek] = useState(ASPEK_FALLBACK); //ASPEK: komponen guru aktif {id, name}
  const [scores, setScores] = useState({}); // { placementId: { [componentId]: score } }
  const [finals, setFinals] = useState({}); // { placementId: finalAssessment }
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [calculating, setCalculating] = useState(null);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  useEffect(() => {
    onMeta?.({ title: "Penilaian", subtitle: "Penilaian detail siswa bimbingan • Periode PKL 2026" });
    (async () => {
      try {
        const [pRes, aRes, cRes, fRes] = await Promise.all([
          getEnrichedPlacements(),
          assessmentsApi.index().catch(() => ({ data: [] })),
          componentsApi.index().catch(() => ({ data: [] })),
          finalsApi.index().catch(() => ({ data: [] })),
        ]);
        setPlacements(pRes || []);
        // Guru hanya mengisi komponen milik guru; fallback statis bila kosong.
        const guruComponents = (cRes?.data ?? []).filter(
          (c) => c.is_active !== false && (!c.owner_type || c.owner_type === "guru")
        );
        if (guruComponents.length > 0) {
          setAspek(guruComponents.map((c) => ({ id: c.id, key: c.name, label: c.name, weight: c.weight })));
        }
        const activeComponents = guruComponents.length > 0 ? guruComponents : [];
        const finalsByPlacement = {};
        for (const f of (fRes?.data ?? [])) {
          finalsByPlacement[f.placement_id] = f;
        }
        setFinals(finalsByPlacement);
        // muat nilai yang sudah ada (per penilai + komponen)
        const existing = {};
        for (const a of (aRes?.data ?? [])) {
          const pid = a.placement_id;
          const cid = a.component_id ?? (activeComponents.find((c) => a.component_name && c.name.toLowerCase() === String(a.component_name).toLowerCase())?.id)
            ?? (ASPEK_FALLBACK.find((x) => a.component_name?.toLowerCase().includes(x.key))?.id ?? null);
          if (!cid) continue;
          if (!existing[pid]) existing[pid] = {};
          existing[pid][cid] = a.score;
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

  const rataRata = (pid) => {
    const sc = scores[pid] || {};
    const vals = aspek.map((a) => sc[a.id]).filter((v) => v !== "" && v != null && !isNaN(v));
    if (!vals.length) return "-";
    return Math.round(vals.reduce((a, b) => a + Number(b), 0) / vals.length);
  };

  const submit = async (e) => {
    e.preventDefault();
    setError("");
    setSuccess("");
    setSaving(true);
    try {
      const cleaned = placements.map((p) => ({ p, vals: aspek
        .map((a) => ({ a, val: scores[p.id]?.[a.id] }))
        .filter((x) => x.val !== "" && x.val != null && !isNaN(x.val)) }));
      if (!cleaned.some((c) => c.vals.length > 0)) {
        setError("Isi minimal satu nilai sebelum menyimpan.");
        setSaving(false);
        return;
      }
      const failures = [];
      for (const { p, vals } of cleaned) {
        for (const { a, val } of vals) {
          try {
            await assessmentsApi.store({
              placement_id: p.id,
              component_id: a.id,
              score: Number(val),
            }).then((res) => {
              if (res?.message && /sudah/i.test(res.message) && res?.status >= 400) {
                failures.push(`${p.student?.name} — ${a.label}: ${res.message}`);
              }
            }).catch((err) => {
              // Tampilkan error backend apa adanya (mis. 422 sudah dinilai, 403 tidak berwenang)
              failures.push(`${p.student?.name} — ${a.label}: ${err?.message || "gagal"}`);
            });
          } catch (err) {
            failures.push(`${p.student?.name} — ${a.label}: ${err?.message || "gagal"}`);
          }
        }
      }
      if (failures.length > 0) {
        setError(`Sebagian penilaian gagal disimpan (${failures.length}). ${failures.slice(0, 3).join(" | ")}`);
      } else {
        setSuccess("Penilaian tersimpan.");
      }
    } catch (err) {
      setError(err?.message || "Gagal menyimpan penilaian.");
    } finally {
      setSaving(false);
    }
  };

  const hitungNilaiAkhir = async (placementId) => {
    setError("");
    setSuccess("");
    setCalculating(placementId);
    try {
      const res = await finalsApi.store({ placement_id: Number(placementId) });
      setFinals((f) => ({ ...f, [placementId]: res?.data }));
      setSuccess(`Nilai akhir dihitung sistem: ${res?.data?.final_score ?? "-"}.`);
    } catch (err) {
      setError(err?.message || "Gagal menghitung nilai akhir.");
    } finally {
      setCalculating(null);
    }
  };

  return (
    <div className="zip-page has-m-cards">
      <div className="zip-info-banner">
        <span className="zip-info-icon"><i className="fa-solid fa-circle-info"></i></span>
        <div>
          <strong>Penilaian tersimpan sebagai draf.</strong> Nilai akhir baru tampil ke siswa
          setelah PKL selesai dan penilaian dikunci sekolah.
        </div>
      </div>

      <form className="zip-card" style={{ maxWidth: "none" }} onSubmit={submit}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 4 }}>
          <div>
            <h3 className="zip-card-title" style={{ margin: 0 }}>Form Penilaian</h3>
            <div className="zip-sub">Skala 0–100 • mempertimbangkan jurnal, evaluasi industri & kedisiplinan</div>
          </div>
          <span className="zip-badge b-gray">● Draf</span>
        </div>

        {error && <div className="zip-error">{error}</div>}
        {success && <div className="zip-success">{success}</div>}

        {loading ? (
          <SkelCards n={2} />
        ) : placements.length === 0 ? (
          <p className="zip-muted">Belum ada siswa bimbingan.</p>
        ) : (
          <div className="zip-table-wrap" style={{ boxShadow: "none", marginTop: 12 }}>
            <table className="zip-table">
                <thead>
                <tr>
                  <th>Siswa</th>
              {aspek.map((a) => (
                    <th key={a.id ?? a.key} style={{ textAlign: "center" }}>{a.label}</th>
                  ))}
                  <th style={{ textAlign: "center" }}>Rata-rata</th>
                  <th style={{ textAlign: "center" }}>Nilai Akhir</th>
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
                    {aspek.map((a) => (
                      <td key={a.id ?? a.key} style={{ textAlign: "center" }}>
                        <input
                          type="number"
                          min="0"
                          max="100"
                          className="zip-score-input"
                          value={scores[p.id]?.[a.id] ?? ""}
                          onChange={(e) => setScore(p.id, a.id, e.target.value)}
                          placeholder="0"
                        />
                      </td>
                    ))}
                    <td style={{ textAlign: "center", fontWeight: 800, fontSize: 15 }}>
                      {rataRata(p.id)}
                    </td>
                    <td style={{ textAlign: "center", fontSize: 13 }}>
                      {finals[p.id] ? (
                        <span>
                          <b>{finals[p.id].final_score}</b>{" "}
                          <span className="zip-badge b-gray">{finals[p.id].status}</span>
                        </span>
                      ) : (
                        <button
                          type="button"
                          className="zip-btn-outline"
                          disabled={calculating === p.id}
                          onClick={() => hitungNilaiAkhir(p.id)}
                        >
                          {calculating === p.id ? "Menghitung…" : "Hitung"}
                        </button>
                      )}
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
                {aspek.map((a) => (
                  <label key={a.id ?? a.key} className="nilai-field">
                    <span>{a.label}</span>
                    <input
                      type="number"
                      min="0"
                      max="100"
                      className="zip-score-input"
                      value={scores[p.id]?.[a.id] ?? ""}
                      onChange={(e) => setScore(p.id, a.id, e.target.value)}
                      placeholder="0"
                    />
                  </label>
                ))}
              </div>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 10 }}>
                <span className="zip-sub">
                  Nilai akhir: {finals[p.id] ? `${finals[p.id].final_score} (${finals[p.id].status})` : "belum dihitung"}
                </span>
                {!finals[p.id] && (
                  <button
                    type="button"
                    className="zip-btn-outline"
                    disabled={calculating === p.id}
                    onClick={() => hitungNilaiAkhir(p.id)}
                  >
                    {calculating === p.id ? "Menghitung…" : "Hitung Nilai Akhir"}
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>

        <div className="nilai-actions">
          <span className="zip-sub">Rata-rata dihitung otomatis dari komponen aktif.</span>
          <button type="submit" className="zip-btn-primary" disabled={saving || loading}>
            <i className="fa-solid fa-floppy-disk"></i> {saving ? "Menyimpan…" : "Simpan Penilaian"}
          </button>
        </div>
      </form>
    </div>
  );
}

export default Penilaian;
