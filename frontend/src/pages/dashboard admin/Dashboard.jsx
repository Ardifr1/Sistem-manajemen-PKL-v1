import { useEffect, useState } from "react";
import { usePolling } from "../../lib/use-polling.js";
import { useNavigate } from "react-router-dom";
import {
  usersApi, companiesApi, applicationsApi,
  placementsApi, journalsApi, periodsApi,
} from "../../api/index.js";
import "./admin-pages.css";

/* Dashboard Admin — sesuai mockup: 6 stat, perlu tindakan, periode aktif,
   pengajuan terbaru, distribusi status pengajuan.
   OPTIMASI: 6 request unik (dulu 12 dgn duplikasi), progressive rendering
   2 fase + skeleton agar UI langsung tampil. */

const STATUS_BADGE = {
  pending: { label: "Diajukan", cls: "blue" },
  submitted: { label: "Diajukan", cls: "blue" },
  diajukan: { label: "Diajukan", cls: "blue" },
  seleksi: { label: "Dalam seleksi", cls: "yellow" },
  reviewed: { label: "Dalam seleksi", cls: "yellow" },
  interview: { label: "Dalam seleksi", cls: "yellow" },
  accepted: { label: "Diterima", cls: "green" },
  approved: { label: "Diterima", cls: "green" },
  diterima: { label: "Diterima", cls: "green" },
  rejected: { label: "Ditolak", cls: "red" },
  ditolak: { label: "Ditolak", cls: "red" },
};
const badgeOf = (s) => STATUS_BADGE[s] || { label: s || "-", cls: "gray" };

const fmtDate = (iso) => {
  if (!iso) return "-";
  const d = new Date(iso);
  if (isNaN(d)) return "-";
  return d.toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" });
};

const arr = (r) => (Array.isArray(r) ? r : r?.data?.data || r?.data || []);
const nameOf = (u) => u?.name ?? u?.nama ?? "-";
const companyName = (c) => c?.name ?? c?.nama ?? c?.company_name ?? "-";

function StatCard({ label, value, sub, loading }) {
  if (loading) {
    return (
      <div className="adm-stat adm-skel">
        <div className="adm-skel-line w40"></div>
        <div className="adm-skel-line w60 big"></div>
        <div className="adm-skel-line w50"></div>
      </div>
    );
  }
  return (
    <div className="adm-stat">
      <div className="adm-stat-label">{label}</div>
      <div className="adm-stat-value">{value}</div>
      <div className="adm-stat-sub">{sub}</div>
    </div>
  );
}

function Dashboard({ onMeta }) {
  const navigate = useNavigate();
  // Fase 1 (cepat): users, companies, applications, placements
  const [stats, setStats] = useState(null);
  const [recent, setRecent] = useState(null);
  const [actions, setActions] = useState(null);
  const [dist, setDist] = useState(null);
  const [distTotal, setDistTotal] = useState(0);
  const [ready, setReady] = useState(false);
  // Fase 2 (menyusul): journals, periods
  const [jurnalAntre, setJurnalAntre] = useState(null);
  const [periode, setPeriode] = useState(null);

  const [refreshKey, setRefreshKey] = useState(0);
  usePolling(() => setRefreshKey((k) => k + 1), 15000);

  useEffect(() => {
    let alive = true;

    /* ---------- FASE 1: data inti (4 request unik) ---------- */
    (async () => {
      const [uRes, cRes, aRes, pRes] = await Promise.all([
        usersApi.index().catch(() => []),
        companiesApi.index().catch(() => []),
        applicationsApi.index().catch(() => []),
        placementsApi.index().catch(() => []),
      ]);
      if (!alive) return;

      const users = arr(uRes);
      const companies = arr(cRes);
      const apps = arr(aRes);
      const placements = arr(pRes);

      // Ringkasan — selalu angka asli dari API, termasuk nol.
      // (Dulu ada fallback angka mockup saat hasil 0; sudah dihapus agar
      // dashboard tidak menampilkan data contoh sebagai data nyata.)
      const isRole = (r) => users.filter((u) => u.role === r).length;
      const total = apps.length;
      const s = {
        siswa: isRole("student"),
        guru: isRole("teacher"),
        perusahaan: companies.length,
        pengajuan: total,
        penempatan: placements.length,
      };

      // Pengajuan terbaru (logika dari adminRecentApplications, tanpa request ganda)
      const userById = Object.fromEntries(users.map((u) => [u.id, u]));
      const companyById = Object.fromEntries(companies.map((c) => [c.id, c]));
      const sorted = [...apps].sort((a, b) =>
        String(b.created_at || b.updated_at || "").localeCompare(String(a.created_at || a.updated_at || "")));
      const recRows = sorted.slice(0, 4).map((a) => {
        const su = userById[a.student_id] || {};
        const co = companyById[a.company_id] || {};
        return {
          id: a.id,
          siswa: nameOf(su),
          kelas: su.class ?? su.kelas ?? "-",
          perusahaan: companyName(co),
          status: a.status,
          tanggal: fmtDate(a.created_at || a.updated_at),
        };
      });

      // Perlu tindakan. (Item lama "penempatan menunggu persetujuan" dihapus:
      // penempatan kini terbit otomatis dari pilihan siswa, tanpa persetujuan.)
      const now = Date.now();
      const seleksiLama = apps.filter((a) => {
        if (!["seleksi", "reviewed", "interview"].includes(a.status)) return false;
        const t = new Date(a.updated_at || a.created_at || 0).getTime();
        return now - t > 7 * 24 * 3600 * 1000;
      }).length;
      const kuotaPenuh = companies.filter((c) => {
        const q = Number(c.student_quota) || 0;
        if (!q) return false;
        const terisi = placements.filter((p) => String(p.company_id) === String(c.id)).length;
        return terisi >= q * 0.8;
      });
      const acts = [];
      if (seleksiLama > 0) acts.push({
        icon: "fa-clock", tone: "blue",
        title: `${seleksiLama} pengajuan seleksi > 7 hari`,
        desc: "Belum ada keputusan perusahaan",
        btn: "Lihat", primary: false, to: "/admin/persetujuan",
      });
      if (kuotaPenuh.length > 0) acts.push({
        icon: "fa-file-lines", tone: "green",
        title: `${kuotaPenuh.length} perusahaan kuota hampir penuh`,
        desc: kuotaPenuh.slice(0, 2).map((c) => {
          const terisi = placements.filter((p) => String(p.company_id) === String(c.id)).length;
          return `${companyName(c)} (${terisi}/${c.student_quota})`;
        }).join(" • "),
        btn: "Lihat", primary: false, to: "/admin/perusahaan",
      });

      // Distribusi status pengajuan — dihitung dari status asli backend
      // (pending, seleksi, interview, accepted, approved, rejected).
      const count = (pred) => apps.filter(pred).length;
      const d = [
        { label: "Diterima perusahaan", n: count((a) => ["accepted", "approved", "diterima"].includes(a.status)), color: "#22c55e" },
        { label: "Dalam seleksi / interview", n: count((a) => ["seleksi", "reviewed", "interview"].includes(a.status)), color: "#f59e0b" },
        { label: "Baru diajukan", n: count((a) => ["pending", "submitted", "diajukan"].includes(a.status)), color: "#3b82f6" },
        { label: "Ditolak", n: count((a) => ["rejected", "ditolak"].includes(a.status)), color: "#cbd5e1" },
      ];

      setStats(s);
      setRecent(recRows);
      setActions(acts);
      setDist(d);
      setDistTotal(total);
      setReady(true); // UI langsung tampil — tidak menunggu fase 2
    })();

    /* ---------- FASE 2: jurnal & periode (2 request unik, menyusul) ---------- */
    (async () => {
      const [jRes, peRes] = await Promise.all([
        journalsApi.index().catch(() => []),
        periodsApi.index().catch(() => []),
      ]);
      if (!alive) return;

      const journals = arr(jRes);
      const periods = arr(peRes);
      const menunggu = journals.filter((j) => ["submitted", "pending", "menunggu"].includes(j.status)).length;
      const aktif = periods.find((p) => p.is_active) || periods[0] || null;

      setJurnalAntre(menunggu);
      // false = fase 2 selesai tetapi belum ada periode sama sekali
      // (null dipakai khusus untuk keadaan masih memuat).
      setPeriode(aktif ?? false);
      onMeta?.({
        subtitle: aktif
          ? `SMK Negeri 1 Yogyakarta • Periode ${aktif.name || aktif.nama || "PKL"}`
          : "SMK Negeri 1 Yogyakarta • Periode PKL",
      });
    })();

    return () => { alive = false; };
  }, [onMeta, refreshKey]);

  const pctPeriode = (() => {
    if (!periode?.start_date || !periode?.end_date) return 0;
    const s = new Date(periode.start_date).getTime();
    const e = new Date(periode.end_date).getTime();
    const n = Date.now();
    if (e <= s) return 0;
    return Math.max(0, Math.min(100, Math.round(((n - s) / (e - s)) * 100)));
  })();

  return (
    <div className="adm-dash">
      {/* 6 kartu stat — skeleton sampai fase 1 tiba */}
      <div className="adm-stats">
        <StatCard label="SISWA" value={stats?.siswa} sub="Akun siswa terdaftar" loading={!ready} />
        <StatCard label="GURU" value={stats?.guru} sub="Pembimbing" loading={!ready} />
        <StatCard label="PERUSAHAAN" value={stats?.perusahaan} sub="Perusahaan terdaftar" loading={!ready} />
        <StatCard label="PENGAJUAN" value={stats?.pengajuan} sub="Total pengajuan" loading={!ready} />
        <StatCard label="PENEMPATAN" value={stats?.penempatan} sub="Penempatan resmi" loading={!ready} />
        <StatCard label="JURNAL ANTRE" value={jurnalAntre} sub="Verifikasi industri" loading={jurnalAntre === null} />
      </div>

      {/* Perlu tindakan + Periode aktif */}
      <div className="adm-grid2">
        <div className="adm-card">
          <div className="adm-card-head">
            <h3>Perlu Tindakan</h3>
            {actions && actions.length > 0 && <span className="adm-badge yellow"><i className="fa-solid fa-circle"></i> {actions.length} item</span>}
          </div>
          {!ready ? (
            <div className="adm-skel-block"><div className="adm-skel-line w80"></div><div className="adm-skel-line w60"></div></div>
          ) : actions.length === 0 ? (
            <p className="zip-muted" style={{ padding: "8px 0" }}>Tidak ada yang perlu tindakan. Semua beres!</p>
          ) : actions.map((a, i) => (
            <div className="adm-action" key={i}>
              <span className={`adm-action-ic ${a.tone}`}><i className={`fa-solid ${a.icon}`}></i></span>
              <span className="adm-action-tx">
                <strong>{a.title}</strong>
                <small>{a.desc}</small>
              </span>
              <button
                type="button"
                className={a.primary ? "zip-btn-primary" : "zip-btn-outline"}
                onClick={() => navigate(a.to)}
              >
                {a.btn}
              </button>
            </div>
          ))}
        </div>

        <div className="adm-card">
          <div className="adm-card-head">
            <h3>Periode Aktif</h3>
            {periode?.is_active && <span className="adm-badge green"><i className="fa-solid fa-circle"></i> AKTIF</span>}
          </div>
          {periode === null ? (
            <div className="adm-skel-block"><div className="adm-skel-line w50 big"></div><div className="adm-skel-line w80"></div><div className="adm-skel-line w80"></div></div>
          ) : periode === false ? (
            <p className="zip-muted" style={{ padding: "8px 0" }}>Belum ada periode PKL.</p>
          ) : (
            <>
              <div className="adm-periode-name">{periode?.name || periode?.nama || "-"}</div>
              <div className="adm-kv"><span>Mulai</span><strong>{fmtDate(periode?.start_date)}</strong></div>
              <div className="adm-kv"><span>Selesai</span><strong>{fmtDate(periode?.end_date)}</strong></div>
              <div className="adm-kv"><span>Progres periode</span><strong>{pctPeriode}%</strong></div>
              <div className="adm-progress"><div className="adm-progress-fill" style={{ width: `${pctPeriode}%` }}></div></div>
            </>
          )}
        </div>
      </div>

      {/* Pengajuan terbaru + Distribusi */}
      <div className="adm-grid2b">
        <div className="adm-card has-m-cards">
          <div className="adm-card-head">
            <div>
              <h3>Pengajuan Terbaru</h3>
              <small className="zip-muted">Aktivitas 7 hari terakhir</small>
            </div>
            <button type="button" className="adm-link" onClick={() => navigate("/admin/persetujuan")}>
              Semua pengajuan <i className="fa-solid fa-chevron-right"></i>
            </button>
          </div>
          {!ready ? (
            <div className="adm-skel-block"><div className="adm-skel-line w90"></div><div className="adm-skel-line w90"></div><div className="adm-skel-line w70"></div></div>
          ) : recent.length === 0 ? (
            <p className="zip-muted" style={{ padding: "8px 0" }}>Belum ada pengajuan.</p>
          ) : (
            <>
              <div className="adm-table-wrap adm-recent-table">
                <table className="adm-table">
                  <thead>
                    <tr><th>SISWA</th><th>PERUSAHAAN TUJUAN</th><th>STATUS</th><th className="right">TANGGAL</th></tr>
                  </thead>
                  <tbody>
                    {recent.map((r) => {
                      const b = badgeOf(r.status);
                      return (
                        <tr key={r.id}>
                          <td><strong>{r.siswa}</strong><small className="adm-td-sub">{r.kelas}</small></td>
                          <td>{r.perusahaan}</td>
                          <td><span className={`adm-pill ${b.cls}`}><i className="fa-solid fa-circle"></i> {b.label}</span></td>
                          <td className="right zip-muted">{r.tanggal}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <div className="m-cards adm-recent-cards">
                {recent.map((r) => {
                  const b = badgeOf(r.status);
                  return (
                    <div className="m-card" key={r.id} onClick={() => navigate("/admin/persetujuan")}>
                      <div className="m-card-top">
                        <div className="m-card-tx">
                          <b>{r.siswa}</b>
                          <small>{r.kelas} • {r.perusahaan}</small>
                        </div>
                        <i className="fa-solid fa-chevron-right m-card-arrow"></i>
                      </div>
                      <div className="m-card-bottom">
                        <span className={`adm-pill ${b.cls}`}><i className="fa-solid fa-circle"></i> {b.label}</span>
                        <span className="zip-muted" style={{ fontSize: 12 }}>{r.tanggal}</span>
                      </div>
                    </div>
                  );
                })}
              </div>
            </>
          )}
        </div>

        <div className="adm-card">
          <div className="adm-card-head">
            <h3>Distribusi Status Pengajuan</h3>
            <span className="zip-muted">{distTotal} total</span>
          </div>
          {!ready ? (
            <div className="adm-skel-block"><div className="adm-skel-line w90"></div><div className="adm-skel-line w80"></div><div className="adm-skel-line w85"></div></div>
          ) : (
            <>
              {dist.map((d, i) => {
                const pct = distTotal ? Math.round((d.n / distTotal) * 100) : 0;
                return (
                  <div className="adm-dist" key={i}>
                    <div className="adm-dist-row"><span>{d.label}</span><strong>{d.n}</strong></div>
                    <div className="adm-bar"><div className="adm-bar-fill" style={{ width: `${pct}%`, background: d.color }}></div></div>
                  </div>
                );
              })}
              <p className="zip-muted" style={{ fontSize: 12.5, marginTop: 14 }}>
                Penempatan resmi ({stats?.penempatan ?? 0}) dibuat otomatis saat siswa memilih tawaran yang diterima perusahaan.
              </p>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

export default Dashboard;
