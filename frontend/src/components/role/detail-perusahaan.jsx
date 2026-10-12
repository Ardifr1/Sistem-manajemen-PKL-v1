export function parseDocs(v) {
  if (!v) return [];
  try {
    const a = JSON.parse(v);
    if (Array.isArray(a)) return a;
  } catch { /* bukan JSON */ }
  return String(v).split(",").map((s) => s.trim()).filter(Boolean);
}

/* ================= MODAL DETAIL PERUSAHAAN ================= */
export function DetailPerusahaan({ c, onClose, onAjukan, diajukan, sending, hideAjukan }) {
  const docs = parseDocs(c.required_documents);
  const pct = c.student_quota ? Math.round(((c._terisi || 0) / c.student_quota) * 100) : 0;
  return (
    <div className="siswa-modal-bg" onClick={onClose}>
      <div className="siswa-modal siswa-modal-detail" onClick={(e) => e.stopPropagation()}>
        <button type="button" className="siswa-modal-x" onClick={onClose} aria-label="Tutup">
          <i className="fa-solid fa-xmark"></i>
        </button>
        <div className="siswa-co" style={{ marginBottom: 6, textAlign: "left" }}>
          <span className="siswa-co-logo" style={{ width: 52, height: 52, fontSize: 20 }}>{String(c.name || "?").charAt(0)}</span>
          <div className="siswa-co-tx">
            <b style={{ fontSize: 17 }}>{c.name}</b>
            <small>{c.industry || "—"}</small>
          </div>
        </div>
        {c.description && <p className="siswa-detail-desc">{c.description}</p>}
        <div className="siswa-detail-kv">
          {c.address && <div><i className="fa-solid fa-location-dot"></i><span>{c.address}</span></div>}
          {c.phone && <div><i className="fa-solid fa-phone"></i><span>{c.phone}</span></div>}
          {c.email && <div><i className="fa-solid fa-envelope"></i><span>{c.email}</span></div>}
          {c.required_skills && <div><i className="fa-solid fa-screwdriver-wrench"></i><span>Keahlian: {c.required_skills}</span></div>}
        </div>
        <div className="siswa-detail-sec">
          <b><i className="fa-solid fa-file-lines"></i> Persyaratan Dokumen</b>
          {docs.length === 0 ? (
            <p className="siswa-muted" style={{ fontSize: 13 }}>Tidak ada persyaratan khusus.</p>
          ) : (
            <ul className="siswa-doc-list">
              {docs.map((d) => (
                <li key={d}><i className="fa-solid fa-circle-check"></i>{d}</li>
              ))}
            </ul>
          )}
        </div>
        <div className="siswa-detail-sec">
          <b><i className="fa-solid fa-users"></i> Kuota</b>
          <div className="siswa-muted" style={{ fontSize: 12, margin: "4px 0" }}>{c._terisi || 0}/{c.student_quota || "—"} terisi</div>
          <div className="siswa-quota"><i style={{ width: `${Math.min(pct, 100)}%` }}></i></div>
        </div>
        {!hideAjukan && (
        <div className="siswa-modal-btns" style={{ marginTop: 20 }}>
          {diajukan ? (
            <span className="siswa-badge s-badge-blue"><span className="dot"></span>Sudah Diajukan</span>
          ) : (
            <button type="button" className="siswa-btn siswa-btn-primary"
              disabled={sending || pct >= 100}
              onClick={() => onAjukan(c)}>
              {sending ? "Mengirim…" : pct >= 100 ? "Kuota Penuh" : "Ajukan ke Perusahaan Ini"}
            </button>
          )}
        </div>
        )}
      </div>
    </div>
  );
}

