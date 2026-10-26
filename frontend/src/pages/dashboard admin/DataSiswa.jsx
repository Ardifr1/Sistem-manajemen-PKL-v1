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
  const [teachers, setTeachers] = useState([]);
  const [profiles, setProfiles] = useState({});
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState("");
  const [detail, setDetail] = useState(null);
  const [teacherId, setTeacherId] = useState("");
  const [savingTeacher, setSavingTeacher] = useState(false);
  const [teacherMsg, setTeacherMsg] = useState({ type: "", text: "" });

  useEffect(() => {
    (async () => {
      try {
        const [uRes, pRes, cRes, tRes] = await Promise.all([
          usersApi.index({ role: "student" }).catch(() => ({ data: [] })),
          placementsApi.index().catch(() => ({ data: [] })),
          companiesApi.index().catch(() => ({ data: [] })),
          usersApi.index({ role: "teacher" }).catch(() => ({ data: [] })),
        ]);
        const studs = (uRes?.data ?? []).filter((u) => u.role === "student");
        setStudents(studs);
        setPlacements(pRes?.data ?? []);
        setCompanies(cRes?.data ?? []);
        setTeachers((tRes?.data ?? []).filter((u) => u.role === "teacher"));

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
  const teacherById = useMemo(
    () => Object.fromEntries(teachers.map((t) => [t.id, t])),
    [teachers]
  );

  /** Buka detail siswa + siapkan form penugasan guru pembimbing. */
  const openDetail = (row) => {
    setDetail(row);
    setTeacherId(row.placement?.teacher_id ? String(row.placement.teacher_id) : "");
    setTeacherMsg({ type: "", text: "" });
  };

  /** Admin menetapkan / mengganti guru pembimbing pada penempatan siswa. */
  const saveTeacher = async () => {
    const placement = detail?.placement;
    if (!placement) return;

    const value = teacherId ? Number(teacherId) : null;
    setSavingTeacher(true);
    setTeacherMsg({ type: "", text: "" });

    try {
      const res = await placementsApi.update(placement.id, { teacher_id: value });
      const saved = res?.data ?? { teacher_id: value };

      setPlacements((prev) =>
        prev.map((p) => (p.id === placement.id ? { ...p, ...saved } : p))
      );
      setDetail((prev) =>
        prev ? { ...prev, placement: { ...prev.placement, ...saved } } : prev
      );
      setTeacherMsg({ type: "ok", text: "Guru pembimbing berhasil disimpan." });
    } catch (e) {
      const firstValidation = e?.validationErrors
        ? Object.values(e.validationErrors).flat()[0]
        : null;
      setTeacherMsg({
        type: "error",
        text: firstValidation || e?.message || "Gagal menyimpan guru pembimbing.",
      });
    } finally {
      setSavingTeacher(false);
    }
  };

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
              <span className="zip-label">Guru Pembimbing</span>
              <div>{pl?.teacher_id ? teacherById[pl.teacher_id]?.name || `Guru #${pl.teacher_id}` : "Belum ditugaskan"}</div>
            </div>
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

        <div className="zip-card">
          <h4 className="zip-card-title">Penugasan Guru Pembimbing</h4>
          {pl ? (
            <>
              <p className="zip-sub" style={{ marginBottom: 14 }}>
                Guru pembimbing hanya dapat mengakses penempatan dan profil siswa yang ditugaskan kepadanya.
              </p>
              <label className="zip-field">
                <span>Guru Pembimbing</span>
                <select value={teacherId} onChange={(e) => setTeacherId(e.target.value)}>
                  <option value="">— Belum ditugaskan —</option>
                  {teachers.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.name}{t.nip ? ` • NIP ${t.nip}` : ""}
                    </option>
                  ))}
                </select>
              </label>
              {teacherMsg.text && (
                <div
                  className={teacherMsg.type === "error" ? "zip-error" : "zip-sub"}
                  style={teacherMsg.type === "ok" ? { color: "#16a34a", fontWeight: 600 } : undefined}
                >
                  {teacherMsg.text}
                </div>
              )}
              <div className="zip-actions" style={{ justifyContent: "flex-end" }}>
                <button type="button" className="zip-btn-primary" disabled={savingTeacher} onClick={saveTeacher}>
                  {savingTeacher ? "Menyimpan…" : "Simpan Guru Pembimbing"}
                </button>
              </div>
            </>
          ) : (
            <div className="zip-muted">
              Siswa belum memiliki penempatan resmi. Penugasan guru pembimbing tersedia setelah ada penempatan.
            </div>
          )}
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
                    <button type="button" className="zip-act zip-act-detail" onClick={() => openDetail(r)}>
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
            <div className="m-card" key={r.id} onClick={() => openDetail(r)}>
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
