import AppShell from "../../components/role/app-shell.jsx";
import { authApi } from "../../api/index.js";

export const PAGE_META = {
  dashboard: {
    title: "Dashboard Admin",
    subtitle: "",
  },
  pengguna: {
    title: "Pengguna",
    subtitle: "Kelola akun • Tambah/Edit/Detail/Hapus",
  },
  siswa: {
    title: "Data Siswa",
    subtitle: "Profil • penempatan • status PKL",
  },
  guru: {
    title: "Data Guru",
    subtitle: "Profil • siswa bimbingan",
  },
  perusahaan: {
    title: "Perusahaan Partner",
    subtitle: "",
  },
  periode: {
    title: "Periode PKL",
    subtitle: "DRAFT • AKTIF • SELESAI",
  },
  persetujuan: {
    title: "Persetujuan Sekolah",
    subtitle: "Diterima perusahaan → resmi • butuh ACC",
  },
  jurnal: {
    title: "Monitoring Jurnal",
    subtitle: "Verifikasi jurnal per perusahaan",
  },
  penilaian: {
    title: "Penilaian Akhir",
    subtitle: "Komponen bobot • publikasi nilai",
  },
  profil: {
    title: "Profil Saya",
    subtitle: "Data diri administrator",
  },
};

const MENUS = [
  { title: "Menu Utama", items: [
    { key: "dashboard", label: "Dashboard", icon: "fa-table-cells-large" },
  ]},
  { title: "Data Master", items: [
    { key: "pengguna", label: "Pengguna", icon: "fa-users" },
    { key: "siswa", label: "Data Siswa", icon: "fa-user-graduate" },
    { key: "guru", label: "Data Guru", icon: "fa-chalkboard-user" },
    { key: "perusahaan", label: "Perusahaan Partner", icon: "fa-building" },
  ]},
  { title: "PKL", items: [
    { key: "periode", label: "Periode PKL", icon: "fa-calendar-days" },
    { key: "persetujuan", label: "Persetujuan", icon: "fa-clipboard-check" },
    { key: "jurnal", label: "Monitoring Jurnal", icon: "fa-book-open" },
    { key: "penilaian", label: "Penilaian Akhir", icon: "fa-award" },
  ]},
];

function AdminLayout({ active = "dashboard", onNavigate, title, subtitle, children }) {
  const meta = PAGE_META[active] || PAGE_META.dashboard;
  const user = authApi.currentUser();
  return (
    <AppShell
      roleLabel="ADMIN"
      menus={MENUS}
      active={active}
      onNavigate={onNavigate}
      user={user}
      userSub="Administrator"
      profileKey="profil"
      title={title || meta.title}
      subtitle={subtitle !== undefined ? subtitle : meta.subtitle || ""}
    >
      {children}
    </AppShell>
  );
}

export default AdminLayout;
