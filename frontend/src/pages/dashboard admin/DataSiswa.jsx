import { useEffect, useMemo, useState } from "react";
import { usersApi, placementsApi, companiesApi, profilesApi } from "../../api/index.js";
import { statusLabel } from "../../lib/role-data.js";
import { UserCell, RoleBadge } from "../../components/admin/user-table.jsx";

/**
 * Admin > Data Siswa — lihat data siswa (read-only).
 * Kelola akun tetap di menu Pengguna.
 */
function DataSiswa({ onMeta }) {
  const [students, setStudents] = useState([]);
  const [placements, setPlacements] = useState([]);
  const [companies, setCompanies] = useState([]);
  const [profiles, setProfiles] = useState({});
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState("");
  const [detail, setDetail] = useState(null);

  useEffect(() => {
    (async () => {
      try {
        const [uRes, pRes, cRes] = await Promise.all([
          usersApi.index({ role: "student" }).catch(() => ({ data: [] })),
          placementsApi.index().catch(() => ({ data: [] })),
          companiesApi.index().catch(() => ({ data: [] })),
        ]);
        const studs = (uRes?.data ?? []).filter((u) => u.role === "student");
        setStudents(studs);
        setPlacements(pRes?.data ?? []);
        setCompanies(cRes?.data ?? []);

        // Profil siswa (NIS, kelas, jurusan) — ambil paralel, abaikan yang gagal.
        const profEntries = await Promise.all(
          studs.map(async (st) => {
            try {
              const r = await profilesApi.show(st.id).catch(() => null);
              return [st.id, r?.data ?? null];
            } catch {
              return [st.id, null];
            }
          })
        );
        setProfiles(Object.fromEntries(profEntries));

        onMeta?.({ title: "Data Siswa", subtitle: `${studs.length} siswa terdaftar` });
      } finally {
        setLoading(false);
      }
    })();
  }, [onMeta]);

  const companyById = useMemo(
    () => Object.fromEntries(companies.map((c) => [c.id, c])),
    [companies]
  );
  const placementByStudent = useMemo(
    () => Object.fromEntries(placements.map((p) => [p.student_id, p])),
    [placements]
  );

  const rows = useMemo(() => {
    const query = q.trim().toLowerCase();
    return students
      .map((st) => {
        const prof = profiles[st.id] || {};
        const pl = placementByStudent[st.id];
        return {
          ...st,
          nis: prof.nis || prof.nisn || "-",
          kelas: prof.kelas || prof.class || "-",
          jurusan: prof.jurusan || prof.major || "-",
          placement: pl || null,
          companyName: pl ? companyById[pl.company_id]?.name || "-" : "-",
        };
      })
      .filter(
        (r) =>
          !query ||
          r.name.toLowerCase().includes(query) ||
          String(r.nis).toLowerCase().includes(query) ||
          r.companyName.toLowerCase().includes(query)
      );
  }, [students, profiles, placementByStudent, companyById, q]);

  if (detail) {
    const pl = detail.placement;
    return (
      <div className="zip-page has-m-cards">
        <div className="zip-toolbar">
          <button type="button" className="zip-btn-outline" onClick={() => setDetail(null)}>
            ← Kembali
          </button>
        </div>
        <div className="zip-card">
          <h3 className="zip-card-title">{detail.name}</h3>
          <div className="zip-detail-grid">
            <div><span className="zip-label">NIS</span><div>{detail.nis}</div></div>
            <div><span className="zip-label">Kelas</span><div>{detail.kelas}</div></div>
            <div><span className="zip-label">Jurusan</span><div>{detail.jurusan}</div></div>
            <div><span className="zip-label">Email</span><div>{detail.email}</div></div>
            <div><span className="zip-label">Perusahaan</span><div>{detail.companyName}</div></div>
            <div>
              <span className="zip-label">Status PKL</span>
              <div style={{ marginTop: 4 }}>
                {!pl ? <span className="zip-badge b-gray">Belum Ditempatkan</span>
                  : pl.status === "active" ? <span className="zip-badge b-green">PKL Aktif</span>
                  : pl.status === "completed" ? <span className="zip-badge b-blue">Selesai</span>
                  : (pl.status === "pending" || pl.status === "submitted") ? <span className="zip-badge b-amber">Menunggu Persetujuan</span>
                  : <span className="zip-badge b-gray">{statusLabel(pl.status)}</span>}
              </div>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="zip-page">
      <div className="zip-toolbar">
        <span className="zip-search">
          <i className="fa-solid fa-magnifying-glass"></i>
          <input
            className="zip-input"
            placeholder="Cari nama / NIS / perusahaan…"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
        </span>
      </div>
      {loading && <div className="zip-muted">Memuat…</div>}
      <div className="zip-table-wrap">
        <table className="zip-table">
          <thead>
            <tr>
              <th>Pengguna</th>
              <th>Peran</th>
              <th>Detail</th>
              <th>Status PKL</th>
              <th style={{ textAlign: "right" }}>Aksi</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => {
              const st = r.placement?.status;
              const badge = !r.placement
                ? <span className="zip-badge b-gray">Belum Ditempatkan</span>
                : st === "active"
                  ? <span className="zip-badge b-green">PKL Aktif</span>
                  : st === "completed"
                    ? <span className="zip-badge b-blue">Selesai</span>
                    : st === "pending" || st === "submitted"
                      ? <span className="zip-badge b-amber">Menunggu Persetujuan</span>
                      : <span className="zip-badge b-gray">{statusLabel(st)}</span>;
              return (
                <tr key={r.id}>
                  <td><UserCell name={r.name} email={r.email} /></td>
                  <td><RoleBadge role="student" /></td>
                  <td><span className="zip-sub">{r.kelas} • NIS {r.nis}</span><br />{r.companyName}</td>
                  <td>{badge}</td>
                  <td style={{ textAlign: "right" }}>
                    <button type="button" className="zip-act zip-act-detail" onClick={() => setDetail(r)}>
                      Detail
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <div className="m-cards">
        {rows.map((r) => {
          const st = r.placement?.status;
          const badge = !r.placement
            ? <span className="zip-badge b-gray">Belum Ditempatkan</span>
            : st === "active"
              ? <span className="zip-badge b-green">PKL Aktif</span>
              : st === "completed"
                ? <span className="zip-badge b-blue">Selesai</span>
                : st === "pending" || st === "submitted"
                  ? <span className="zip-badge b-amber">Menunggu Persetujuan</span>
                  : <span className="zip-badge b-gray">{statusLabel(st)}</span>;
          return (
            <div className="m-card" key={r.id} onClick={() => setDetail(r)}>
              <div className="m-card-top">
                <div className="m-card-tx">
                  <b>{r.name}</b>
                  <small>{r.kelas} • NIS {r.nis}</small>
                  <small>{r.companyName || "-"}</small>
                </div>
                <i className="fa-solid fa-chevron-right m-card-arrow"></i>
              </div>
              <div className="m-card-bottom">{badge}</div>
            </div>
          );
        })}
      </div>
      {!loading && rows.length === 0 && (
        <div className="zip-muted">Tidak ada data siswa.</div>
      )}
    </div>
  );
}

export default DataSiswa;
