import { useEffect, useState } from "react";
import { finalAssessmentsApi } from "../../api/index.js";
import { SkelCards } from "../../components/role/skeleton.jsx";

/**
 * Publikasi Nilai (khusus admin):
 * - Publikasikan nilai akhir (wajib PKL completed + perhitungan valid)
 * - Batalkan publikasi dengan konfirmasi + catatan (tercatat di log audit)
 */
function PublikasiNilai({ onMeta }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [acting, setActing] = useState(null);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const [unpubNote, setUnpubNote] = useState({});
  const [confirmUnpub, setConfirmUnpub] = useState(null);

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const res = await finalAssessmentsApi.index();
      setRows(Array.isArray(res?.data) ? res.data : []);
    } catch (err) {
      setError(err?.message || "Gagal memuat nilai akhir.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    onMeta?.({ title: "Publikasi Nilai", subtitle: "Terbitkan / batalkan nilai akhir siswa" });
    let active = true;
    finalAssessmentsApi.index()
      .then((res) => {
        if (active) setRows(Array.isArray(res?.data) ? res.data : []);
      })
      .catch((err) => {
        if (active) setError(err?.message || "Gagal memuat nilai akhir.");
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [onMeta]);

  const publish = async (fa) => {
    if (!window.confirm(`Publikasikan nilai akhir ${fa.placement?.student?.name || ""} (${fa.final_score})? Siswa akan dapat melihat nilainya.`)) return;
    setActing(fa.id); setError(""); setSuccess("");
    try {
      await finalAssessmentsApi.publish(fa.id);
      setSuccess("Nilai akhir dipublikasikan.");
      await load();
    } catch (err) {
      setError(err?.message || "Gagal mempublikasikan.");
    } finally {
      setActing(null);
    }
  };

  const unpublish = async (fa) => {
    const reason = unpubNote[fa.id]?.trim() || "";
    if (reason.length < 5) {
      setError("Alasan pembatalan wajib diisi (min. 5 karakter).");
      return;
    }
    setActing(fa.id); setError(""); setSuccess("");
    try {
      await finalAssessmentsApi.unpublish(fa.id, reason);
      setSuccess("Publikasi dibatalkan. Nilai tidak lagi tampil ke siswa.");
      setConfirmUnpub(null);
      await load();
    } catch (err) {
      setError(err?.message || "Gagal membatalkan publikasi.");
    } finally {
      setActing(null);
    }
  };

  const badge = (s) => s === "published" ? "b-green" : "b-amber";
  const label = (s) => s === "published" ? "Dipublikasikan" : "Draf";

  const renderRows = (isMobile) => rows.map((fa) => {
    const st = fa.placement?.student;
    const comp = fa.placement?.company;
    if (isMobile) {
      return (
        <div className="m-card" key={fa.id}>
          <div className="m-card-top">
            <div className="m-card-tx">
              <b>{st?.name || "-"}</b>
              <small>{comp?.name || "-"}</small>
            </div>
            <div className="nilai-rata"><b>{fa.final_score}</b></div>
          </div>
          <div className="m-card-bottom">
            <span className={`zip-badge ${badge(fa.status)}`}>{label(fa.status)}</span>
            {fa.status === "published" && fa.publisher && (
              <small className="zip-muted">oleh {fa.publisher.name}</small>
            )}
          </div>
          <div className="m-card-actions">
            {fa.status === "published" ? (
              <button type="button" className="zip-act zip-act-hapus" disabled={acting === fa.id}
                onClick={() => setConfirmUnpub(fa.id)}>Batalkan Publikasi</button>
            ) : (
              <button type="button" className="zip-act zip-act-edit" disabled={acting === fa.id}
                onClick={() => publish(fa)}>Publikasikan</button>
            )}
          </div>
          {confirmUnpub === fa.id && (
            <div style={{ marginTop: 10 }}>
              <input className="zip-input" placeholder="Alasan pembatalan (min. 5 karakter)"
                value={unpubNote[fa.id] || ""} onChange={(e) => setUnpubNote((n) => ({ ...n, [fa.id]: e.target.value }))} />
              <div style={{ display: "flex", gap: 8, marginTop: 8 }}>
                <button type="button" className="zip-btn-outline" onClick={() => setConfirmUnpub(null)}>Batal</button>
                <button type="button" className="zip-btn-danger" disabled={acting === fa.id || (unpubNote[fa.id] || "").trim().length < 5} onClick={() => unpublish(fa)}>
                  Ya, Batalkan
                </button>
              </div>
            </div>
          )}
        </div>
      );
    }
    return (
      <tr key={fa.id}>
        <td><b>{st?.name || "-"}</b><div className="zip-muted" style={{ fontSize: 12 }}>{comp?.name || "-"}</div></td>
        <td style={{ textAlign: "center", fontWeight: 800 }}>{fa.final_score}</td>
        <td><span className={`zip-badge ${badge(fa.status)}`}>{label(fa.status)}</span></td>
        <td className="zip-muted" style={{ fontSize: 12 }}>
          {fa.status === "published" && fa.published_at
            ? `${new Date(fa.published_at).toLocaleString("id-ID")} ${fa.publisher ? `• ${fa.publisher.name}` : ""}`
            : "-"}
        </td>
        <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
          {fa.status === "published" ? (
            <button type="button" className="zip-act zip-act-hapus" disabled={acting === fa.id}
              onClick={() => setConfirmUnpub(fa.id)}>Batalkan Publikasi</button>
          ) : (
            <button type="button" className="zip-act zip-act-edit" disabled={acting === fa.id}
              onClick={() => publish(fa)}>Publikasikan</button>
          )}
          {confirmUnpub === fa.id && (
            <div style={{ marginTop: 8, textAlign: "left" }}>
              <input className="zip-input" placeholder="Alasan pembatalan (min. 5 karakter)"
                value={unpubNote[fa.id] || ""} onChange={(e) => setUnpubNote((n) => ({ ...n, [fa.id]: e.target.value }))} />
              <div style={{ display: "flex", gap: 8, marginTop: 8 }}>
                <button type="button" className="zip-btn-outline" onClick={() => setConfirmUnpub(null)}>Batal</button>
                <button type="button" className="zip-btn-danger" disabled={acting === fa.id || (unpubNote[fa.id] || "").trim().length < 5} onClick={() => unpublish(fa)}>
                  Ya, Batalkan
                </button>
              </div>
            </div>
          )}
        </td>
      </tr>
    );
  });

  return (
    <div className="zip-page has-m-cards">
      <div className="zip-info-banner">
        <span className="zip-info-icon"><i className="fa-solid fa-circle-info"></i></span>
        <div>
          Hanya admin yang dapat memublikasikan/membatalkan. Publikasi mensyaratkan PKL
          <strong> selesai</strong> dan perhitungan valid. Setiap perubahan tercatat di log audit.
        </div>
      </div>
      {error && <div className="zip-error">{error}</div>}
      {success && <div className="zip-success">{success}</div>}

      {loading ? <SkelCards n={3} /> : rows.length === 0 ? (
        <p className="zip-muted">Belum ada nilai akhir yang dihitung guru.</p>
      ) : (
        <>
          <div className="zip-table-wrap">
            <table className="zip-table">
              <thead><tr><th>Siswa</th><th style={{ textAlign: "center" }}>Nilai</th><th>Status</th><th>Dipublikasikan</th><th style={{ textAlign: "right" }}>Aksi</th></tr></thead>
              <tbody>{renderRows(false)}</tbody>
            </table>
          </div>
          <div className="m-cards">{renderRows(true)}</div>
        </>
      )}
    </div>
  );
}

export default PublikasiNilai;
