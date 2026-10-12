import { useEffect, useState } from "react";
import { assessmentsApi, componentsApi } from "../../api/index.js";
import {
  formatDateShort,
  statusLabel,
} from "../../lib/role-data.js";
import "./detail-siswa.css";

/** Bintang 1–5 ala mockup. */
function Stars({ value }) {
  const n = Math.max(0, Math.min(5, Math.round(value)));
  return (
    <span className="ds-stars" aria-label={`${n} dari 5`}>
      {[1, 2, 3, 4, 5].map((i) => (
        <span key={i} className={i <= n ? "on" : "off"}>
          ★
        </span>
      ))}
    </span>
  );
}

/**
 * Detail siswa ala mockup 02.02A / 03.02A.
 * variant "guru": Riwayat jurnal (read-only) + Evaluasi perusahaan (ringkas)
 * variant "industri": Jurnal terakhir + Perkembangan
 */
function DetailSiswa({ placement: p, journals, attendances = [], variant = "guru", onBack }) {
  const [assessments, setAssessments] = useState([]);
  const [components, setComponents] = useState([]);

  useEffect(() => {
    (async () => {
      try {
        const [aRes, cRes] = await Promise.all([
          assessmentsApi.index({ placement_id: p.id }).catch(() => ({ data: [] })),
          componentsApi.index().catch(() => ({ data: [] })),
        ]);
        setAssessments(aRes?.data ?? []);
        setComponents(cRes?.data ?? []);
      } catch {
        /* abaikan */
      }
    })();
  }, [p.id]);

  const verified = journals.filter((j) => j.status === "verified").length;
  const companyEvals = assessments.filter((a) => a.assessor_role === "company");
  const compName = (id) =>
    components.find((c) => String(c.id) === String(id))?.name || "Aspek";

  const kelas =
    p.student?.student_profile?.class ||
    p.student?.class ||
    p.student?.kelas ||
    "";
  const initial = (p.student?.name || "?").trim().charAt(0).toUpperCase();

  const periodLabel =
    p.pkl_period?.name ||
    p.pklPeriod?.name ||
    (p.start_date ? `PKL ${new Date(p.start_date).getFullYear()}` : "Periode PKL");

  return (
    <div className="zip-page">
      <div className="zip-toolbar">
        <button type="button" className="zip-btn-outline" onClick={onBack}>
          ← Kembali
        </button>
      </div>

      <div className="zip-card ds-head">
        <span className={`ds-avatar ds-avatar-${variant}`} aria-hidden="true">
          {initial}
        </span>
        <div>
          <div className="ds-head-title">
            {p.student?.name}
            {kelas ? ` • ${kelas}` : ""} • {p.company?.name || "-"} — PKL{" "}
            {statusLabel(p.status)}
          </div>
          <div className="zip-muted">
            {periodLabel} • {verified}/{journals.length} jurnal disetujui
          </div>
        </div>
      </div>

      <div className="ds-cols">
        <div className="zip-card">
          <h4>{variant === "guru" ? "Riwayat jurnal (read-only)" : "Jurnal terakhir"}</h4>
          <div className="ds-rows">
            {journals.slice(0, 6).map((j) => (
              <div className="ds-row" key={j.id}>
                {formatDateShort(j.journal_date)} •{" "}
                {(j.revised_activity || j.activity || "").slice(0, 40)} —{" "}
                {statusLabel(j.status)}
              </div>
            ))}
            {journals.length === 0 && (
              <div className="zip-muted">Belum ada jurnal.</div>
            )}
          </div>
        </div>

        {variant === "guru" ? (
          <div className="zip-card">
            <h4>Evaluasi perusahaan (ringkas)</h4>
            {companyEvals.length === 0 && (
              <div className="zip-muted">
                Belum ada evaluasi dari perusahaan — tanpa tombol verifikasi.
              </div>
            )}
            <div className="ds-rows">
              {companyEvals.slice(0, 5).map((a) => (
                <div className="ds-row" key={a.id}>
                  {compName(a.component_id)}{" "}
                  <Stars value={Number(a.score) / 20} />
                </div>
              ))}
            </div>
          </div>
        ) : (
          <>
          <div className="zip-card">
            <h4>Rekap Absensi</h4>
            {attendances.length === 0 ? (
              <div className="zip-muted">Belum ada data absensi.</div>
            ) : (
              <div className="ds-rows">
                {["hadir", "izin", "sakit", "alpa"].map((st) => {
                  const n = attendances.filter((a) => String(a.status).toLowerCase() === st).length;
                  return (
                    <div className="ds-row" key={st}>
                      <span className={`zip-badge ${st === "hadir" ? "b-green" : st === "alpa" ? "b-red" : "b-amber"}`}>
                        {st.charAt(0).toUpperCase() + st.slice(1)}
                      </span>
                      <b style={{ marginLeft: 8 }}>{n}</b>
                    </div>
                  );
                })}
                <div className="zip-muted" style={{ marginTop: 6, fontSize: 12 }}>
                  Total {attendances.length} catatan kehadiran
                </div>
              </div>
            )}
          </div>
          <div className="zip-card">
            <h4>Perkembangan</h4>
            <div className="zip-muted">
              {verified}/{journals.length} jurnal disetujui
              {companyEvals.length > 0
                ? ` • evaluasi terakhir: ${(
                    companyEvals[companyEvals.length - 1]?.note || "tanpa catatan"
                  ).slice(0, 80)}`
                : " • belum ada evaluasi."}{" "}
              Lihat verifikasi untuk aksi Setujui/Revisi.
            </div>
          </div>
          </>
        )}
      </div>
    </div>
  );
}

export default DetailSiswa;
