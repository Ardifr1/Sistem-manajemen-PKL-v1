import { useEffect, useState } from "react";
import { feedbacksApi } from "../../api/index.js";
import { SkelCards } from "../../components/role/skeleton.jsx";

/**
 * Review Feedback (admin, BR-19): setujui/tolak feedback siswa
 * sebelum menjadi referensi.
 */
function ReviewFeedback({ onMeta }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState("pending");
  const [acting, setActing] = useState(null);
  const [error, setError] = useState("");

  const load = async () => {
    setLoading(true);
    try {
      const res = await feedbacksApi.index().catch(() => ({ data: [] }));
      setRows(Array.isArray(res?.data) ? res.data : []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    onMeta?.({ title: "Review Feedback", subtitle: "Setujui/tolak feedback siswa (BR-19)" });
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [onMeta]);

  const act = async (id, status) => {
    setActing(id);
    setError("");
    try {
      await feedbacksApi.review(id, { status });
      await load();
    } catch (err) {
      setError(err?.message || "Gagal memproses feedback.");
    } finally {
      setActing(null);
    }
  };

  const counts = {
    pending: rows.filter((r) => r.status === "pending").length,
    approved: rows.filter((r) => r.status === "approved").length,
    rejected: rows.filter((r) => r.status === "rejected").length,
  };
  const list = rows.filter((r) => (r.status || "pending") === tab);

  const badge = (s) => s === "approved" ? "b-green" : s === "rejected" ? "b-red" : "b-amber";
  const label = (s) => s === "approved" ? "Disetujui" : s === "rejected" ? "Ditolak" : "Menunggu";

  return (
    <div className="zip-page has-m-cards">
      {error && <div className="zip-error">{error}</div>}
      <div className="zip-tabs" style={{ marginBottom: 14 }}>
        {[["pending", `Menunggu (${counts.pending})`], ["approved", `Disetujui (${counts.approved})`], ["rejected", `Ditolak (${counts.rejected})`]].map(([k, l]) => (
          <button key={k} type="button" className={`zip-tab${tab === k ? " active" : ""}`} onClick={() => setTab(k)}>{l}</button>
        ))}
      </div>

      {loading ? <SkelCards n={3} /> : list.length === 0 ? (
        <p className="zip-muted">Tidak ada feedback pada tab ini.</p>
      ) : (
        <>
          <div className="zip-table-wrap">
            <table className="zip-table">
              <thead><tr><th>Siswa</th><th>Perusahaan</th><th>Isi</th><th>Status</th><th style={{ textAlign: "right" }}>Aksi</th></tr></thead>
              <tbody>
                {list.map((f) => (
                  <tr key={f.id}>
                    <td><b>{f.student?.name || "-"}</b></td>
                    <td>{f.company?.name || "-"}</td>
                    <td style={{ maxWidth: 320 }}><span className="zip-muted">{String(f.content || f.message || "").slice(0, 120)}</span></td>
                    <td><span className={`zip-badge ${badge(f.status)}`}>{label(f.status)}</span></td>
                    <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                      {f.status === "pending" && (
                        <>
                          <button type="button" className="zip-act zip-act-edit" style={{ marginRight: 6 }}
                            disabled={acting === f.id} onClick={() => act(f.id, "approved")}>Setujui</button>
                          <button type="button" className="zip-act zip-act-hapus"
                            disabled={acting === f.id} onClick={() => act(f.id, "rejected")}>Tolak</button>
                        </>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="m-cards">
            {list.map((f) => (
              <div className="m-card" key={f.id}>
                <div className="m-card-top">
                  <div className="m-card-tx">
                    <b>{f.student?.name || "-"}</b>
                    <small>{f.company?.name || "-"}</small>
                  </div>
                </div>
                <p className="zip-muted" style={{ fontSize: 13, margin: "8px 0" }}>{String(f.content || f.message || "").slice(0, 160)}</p>
                <div className="m-card-bottom">
                  <span className={`zip-badge ${badge(f.status)}`}>{label(f.status)}</span>
                </div>
                {f.status === "pending" && (
                  <div className="m-card-actions">
                    <button type="button" className="zip-act zip-act-edit" disabled={acting === f.id} onClick={() => act(f.id, "approved")}>Setujui</button>
                    <button type="button" className="zip-act zip-act-hapus" disabled={acting === f.id} onClick={() => act(f.id, "rejected")}>Tolak</button>
                  </div>
                )}
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  );
}

export default ReviewFeedback;
