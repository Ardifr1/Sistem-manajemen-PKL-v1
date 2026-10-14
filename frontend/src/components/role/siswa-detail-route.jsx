import { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { journalsApi, attendancesApi } from "../../api/index.js";
import { getEnrichedPlacements } from "../../lib/role-data.js";
import DetailSiswa from "./detail-siswa.jsx";

/**
 * Bungkus DetailSiswa untuk route /siswa/:id.
 * Ambil placement by ID dari URL — jadi di-refresh pun tetap kebuka.
 */
function SiswaDetailRoute({ variant = "guru", listPath, onMeta }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const [placement, setPlacement] = useState(null);
  const [journals, setJournals] = useState([]);
  const [attendances, setAttendances] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    (async () => {
      try {
        const rows = await getEnrichedPlacements();
        const p = rows.find((r) => String(r.id) === String(id)) || null;
        setPlacement(p);
        onMeta?.({
          title: p ? `Detail Siswa — ${p.student?.name || ""}` : "Detail Siswa",
          subtitle:
            variant === "guru"
              ? "Profil • penempatan • jurnal • evaluasi"
              : "Profil • status • jurnal • perkembangan",
        });
        if (p) {
          try {
            const res = await journalsApi.index({ placement_id: p.id });
            setJournals(res?.data ?? []);
          } catch {
            /* abaikan */
          }
          if (variant === "industri") {
            try {
              const aRes = await attendancesApi.index({ placement_id: p.id });
              setAttendances(aRes?.data ?? []);
            } catch {
              /* abaikan */
            }
          }
        }
      } finally {
        setLoading(false);
      }
    })();
  }, [id, variant, onMeta]);

  if (loading) {
    return (
      <div className="zip-page">
        <div className="zip-muted">Memuat…</div>
      </div>
    );
  }

  if (!placement) {
    return (
      <div className="zip-page">
        <div className="zip-muted">Data siswa tidak ditemukan.</div>
      </div>
    );
  }

  return (
    <DetailSiswa
      placement={placement}
      journals={journals}
      attendances={attendances}
      variant={variant}
      onBack={() => navigate(listPath)}
    />
  );
}

export default SiswaDetailRoute;
