import { useState } from "react";
import { Routes, Route, Navigate, useNavigate, useLocation } from "react-router-dom";
import AppShell from "../../components/role/app-shell.jsx";
import { authApi } from "../../api/index.js";
import "../dashboard admin/admin-pages.css";
import "./perusahaan-pages.css";
import Dashboard from "./PerusahaanDashboard.jsx";
import ProfilPerusahaan from "./ProfilPerusahaan.jsx";
import PengajuanMagang from "./PengajuanMagang.jsx";
import DaftarSiswa from "./DaftarSiswa.jsx";
import SeleksiInterview from "./SeleksiInterview.jsx";
import PembimbingKelola from "./PembimbingKelola.jsx";
import EvaluasiPerusahaan from "./EvaluasiPerusahaan.jsx";

const MENUS = [
  { title: "Menu Utama", items: [
    { key: "dashboard", label: "Dashboard", icon: "fa-table-cells-large", path: "/perusahaan" },
    { key: "profil", label: "Profil Perusahaan", icon: "fa-building", path: "/perusahaan/profil" },
    { key: "pengajuan", label: "Pengajuan Magang", icon: "fa-inbox", path: "/perusahaan/pengajuan" },
    { key: "interview", label: "Seleksi & Interview", icon: "fa-comments", path: "/perusahaan/interview" },
    { key: "siswa", label: "Daftar Siswa", icon: "fa-users", path: "/perusahaan/siswa" },
    { key: "pembimbing", label: "Pembimbing", icon: "fa-user-tie", path: "/perusahaan/pembimbing" },
    { key: "evaluasi", label: "Evaluasi", icon: "fa-star", path: "/perusahaan/evaluasi" },
  ]},
];

const META = {
  dashboard: { title: "Dashboard Perusahaan", subtitle: "" },
  profil: { title: "Profil Perusahaan", subtitle: "" },
  pengajuan: { title: "Pengajuan Magang", subtitle: "Review lamaran • dikelompokkan per sekolah" },
  interview: { title: "Seleksi & Interview", subtitle: "Jadwal interview • hasil penilaian" },
  pembimbing: { title: "Pembimbing Industri", subtitle: "Buat akun pembimbing • tugaskan siswa" },
  evaluasi: { title: "Evaluasi", subtitle: "Nilai siswa magang" },
};

function activeKey(pathname) {
  if (pathname.startsWith("/perusahaan/profil")) return "profil";
  if (pathname.startsWith("/perusahaan/pengajuan")) return "pengajuan";
  if (pathname.startsWith("/perusahaan/interview")) return "interview";
  if (pathname.startsWith("/perusahaan/siswa")) return "siswa";
  if (pathname.startsWith("/perusahaan/pembimbing")) return "pembimbing";
  if (pathname.startsWith("/perusahaan/evaluasi")) return "evaluasi";
  return "dashboard";
}

function DashboardPerusahaan() {
  const navigate = useNavigate();
  const location = useLocation();
  const [meta, setMeta] = useState({});
  const key = activeKey(location.pathname);
  const base = META[key] || META.dashboard;
  const user = authApi.currentUser();

  const goMenu = (menuKey) => {
    setMeta({});
    const m = MENUS[0].items.find((x) => x.key === menuKey);
    if (m) navigate(m.path);
  };

  return (
    <AppShell
      roleLabel="PERUSAHAAN"
      menus={MENUS}
      active={key}
      onNavigate={goMenu}
      user={user}
      userSub={user?.company_name || "Perusahaan Mitra"}
      title={meta.title || base.title}
      subtitle={meta.subtitle !== undefined ? meta.subtitle : base.subtitle}
      profileKey="profil"
    >
      <Routes>
        <Route index element={<Dashboard onMeta={setMeta} />} />
        <Route path="profil" element={<ProfilPerusahaan onMeta={setMeta} />} />
        <Route path="pengajuan" element={<PengajuanMagang onMeta={setMeta} />} />
        <Route path="interview" element={<SeleksiInterview onMeta={setMeta} />} />
        <Route path="siswa" element={<DaftarSiswa onMeta={setMeta} />} />
        <Route path="pembimbing" element={<PembimbingKelola onMeta={setMeta} />} />
        <Route path="evaluasi" element={<EvaluasiPerusahaan onMeta={setMeta} />} />
        <Route path="*" element={<Navigate to="/perusahaan" replace />} />
      </Routes>
    </AppShell>
  );
}

export default DashboardPerusahaan;
