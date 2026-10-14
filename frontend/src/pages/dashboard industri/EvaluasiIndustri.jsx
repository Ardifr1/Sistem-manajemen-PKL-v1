import { useEffect, useState } from "react";
import { assessmentsApi, componentsApi } from "../../api/index.js";
import { SkelCards } from "../../components/role/skeleton.jsx";
import {
  getEnrichedPlacements,
  getMyCompanyId,
} from "../../lib/role-data.js";

function EvaluasiIndustri({ onMeta }) {
  const [placements, setPlacements] = useState([]);
  const [components, setComponents] = useState([]);
  const [loading, setLoading] = useState(true);

  const [placementId, setPlacementId] = useState("");
  const [values, setValues] = useState({});
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [sent, setSent] = useState(false);

  useEffect(() => {
    onMeta?.({ title: "Evaluasi Siswa", subtitle: "Disiplin • sikap • kompetensi • feedback" });
    (async () => {
      try {
        const companyId = await getMyCompanyId();
        let rows = await getEnrichedPlacements();
        if (companyId) {
          rows = rows.filter((p) => String(p.company_id) === String(companyId));
        }
        setPlacements(rows.filter((p) => p.status === "active" || p.status === "completed"));
        const cRes = await componentsApi.index().catch(() => ({ data: [] }));
        // Pembimbing industri hanya mengisi komponen milik industri.
        setComponents(
          (cRes?.data ?? []).filter(
            (c) => c.is_active !== false && (!c.owner_type || c.owner_type === "industri")
          )
        );
      } finally {
        setLoading(false);
      }
    })();
  }, [onMeta]);

  const submit = async (e) => {
    e.preventDefault();
    setError("");
    if (!placementId) {
      setError("Pilih siswa dulu.");
      return;
    }
    const entries = components
      .map((c) => ({ component: c, value: Number(values[c.id]) }))
      .filter((x) => x.value >= 1 && x.value <= 5);

    if (entries.length !== components.length) {
      setError("Isi semua aspek dengan nilai 1–5.");
      return;
    }
    setSaving(true);
    try {
      for (const { component, value } of entries) {
        await assessmentsApi.store({
          placement_id: Number(placementId),
          component_id: component.id,
          score: value * 20,
          note: note.trim() || null,
        });
      }
      setSent(true);
      setValues({});
      setNote("");
    } catch (err) {
      setError(err?.message || "Gagal mengirim evaluasi.");
    } finally {
      setSaving(false);
    }
  };

  const studentName = placements.find((p) => String(p.id) === String(placementId))?.student?.name;

  return (
    <div className="zip-page">
      {loading && <SkelCards n={2} />}

      {sent && (
        <div className="industri-success" style={{ marginBottom: 16 }}>
          Evaluasi untuk <strong>{studentName}</strong> terkirim. Terima kasih atas
          penilaiannya.
        </div>
      )}

      <form className="zip-card zip-form" onSubmit={submit}>
        {error && <div className="zip-error">{error}</div>}
        <label className="zip-field">
          <span>Siswa yang dinilai</span>
          <select value={placementId} onChange={(e) => setPlacementId(e.target.value)}>
            <option value="">— Pilih siswa —</option>
            {placements.map((p) => (
              <option key={p.id} value={p.id}>
                {p.student?.name}
              </option>
            ))}
          </select>
        </label>

        <div className="zip-muted" style={{ marginBottom: 8 }}>
          {studentName ? `${studentName} • nilai skala 1–5 + catatan` : "Nilai skala 1–5 + catatan"}
        </div>

        {components.map((c) => (
          <div className="industri-aspek" key={c.id}>
            <span className="industri-aspek-label">{c.name}</span>
            <label className="zip-field">
              <select
                value={values[c.id] ?? ""}
                onChange={(e) => setValues((v) => ({ ...v, [c.id]: e.target.value }))}
              >
                <option value="">—</option>
                {[1, 2, 3, 4, 5].map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </select>
            </label>
          </div>
        ))}
        {!loading && components.length === 0 && (
          <div className="zip-muted">
            Komponen penilaian belum diatur oleh admin.
          </div>
        )}

        <label className="zip-field">
          <span>Catatan / Perkembangan</span>
          <textarea
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="cth Baik — siap deploy mandiri"
            rows={3}
          />
        </label>

        <div className="zip-form-actions">
          <button type="submit" className="zip-btn-primary" disabled={saving}>
            {saving ? "Mengirim…" : "Kirim Evaluasi"}
          </button>
        </div>
      </form>
    </div>
  );
}

export default EvaluasiIndustri;
