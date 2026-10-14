import { useState } from "react";
import { Routes, Route, Navigate, useNavigate, useLocation } from "react-router-dom";
import AdminLayout from "../../layouts/admin layout/admin";
import "./admin-pages.css";
import Dashboard from "./Dashboard.jsx";
import Pengguna from "./AkunPengguna.jsx";
import PerusahaanPartner from "./PerusahaanMitra.jsx";
import PeriodePKL from "./PeriodePKL.jsx";
import Persetujuan from "./PengajuanPKL.jsx";
import DataSiswa from "./DataSiswa.jsx";
import DataGuru from "./DataGuru.jsx";
import MonitoringJurnal from "./MonitoringJurnal.jsx";
import PenilaianAdmin from "./PenilaianAdmin.jsx";
import ProfilAdmin from "./ProfilAdmin.jsx";

const MENUS = [
  { key: "dashboard", path: "/admin" },
  { key: "pengguna", path: "/admin/pengguna" },
  { key: "siswa", path: "/admin/siswa" },
  { key: "guru", path: "/admin/guru" },
  { key: "perusahaan", path: "/admin/perusahaan" },
  { key: "periode", path: "/admin/periode" },
  { key: "persetujuan", path: "/admin/persetujuan" },
  { key: "jurnal", path: "/admin/jurnal" },
  { key: "penilaian", path: "/admin/penilaian" },
  { key: "profil", path: "/admin/profil" },
];

function activeKey(pathname) {
  if (pathname.startsWith("/admin/pengguna")) return "pengguna";
  if (pathname.startsWith("/admin/siswa")) return "siswa";
  if (pathname.startsWith("/admin/guru")) return "guru";
  if (pathname.startsWith("/admin/perusahaan")) return "perusahaan";
  if (pathname.startsWith("/admin/periode")) return "periode";
  if (pathname.startsWith("/admin/persetujuan")) return "persetujuan";
  if (pathname.startsWith("/admin/jurnal")) return "jurnal";
  if (pathname.startsWith("/admin/penilaian")) return "penilaian";
  if (pathname.startsWith("/admin/profil")) return "profil";
  return "dashboard";
}

function DashboardAdmin() {
  const navigate = useNavigate();
  const location = useLocation();
  const [meta, setMeta] = useState({});
  const key = activeKey(location.pathname);

  const goMenu = (menuKey) => {
    setMeta({});
    const m = MENUS.find((x) => x.key === menuKey);
    if (m) navigate(m.path);
  };

  return (
    <AdminLayout
      active={key}
      onNavigate={goMenu}
      title={meta.title}
      subtitle={meta.subtitle !== undefined ? meta.subtitle : undefined}
    >
      <Routes>
        <Route index element={<Dashboard onMeta={setMeta} />} />
        <Route path="pengguna" element={<Pengguna onMeta={setMeta} />} />
        <Route path="siswa" element={<DataSiswa onMeta={setMeta} />} />
        <Route path="guru" element={<DataGuru onMeta={setMeta} />} />
        <Route path="perusahaan" element={<PerusahaanPartner onMeta={setMeta} />} />
        <Route path="periode" element={<PeriodePKL onMeta={setMeta} />} />
        <Route path="persetujuan" element={<Persetujuan onMeta={setMeta} />} />
        <Route path="jurnal" element={<MonitoringJurnal onMeta={setMeta} />} />
        <Route path="penilaian" element={<PenilaianAdmin onMeta={setMeta} />} />
        <Route path="profil" element={<ProfilAdmin onMeta={setMeta} />} />
        <Route path="*" element={<Navigate to="/admin" replace />} />
      </Routes>
    </AdminLayout>
  );
}

export default DashboardAdmin;
