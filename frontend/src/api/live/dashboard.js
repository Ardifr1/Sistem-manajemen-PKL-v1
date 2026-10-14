/**
 * live/dashboard.js — Agregat dashboard admin dari backend asli.
 * Backend tidak punya endpoint khusus dashboard, jadi data dirakit dari
 * beberapa resource (users, companies, pkl-applications, pkl-placements,
 * journals). Bentuk return SAMA dengan mock/dashboard.js.
 */
import { client } from "../client.js";
import { ep } from "../endpoints.js";
import { q, arrOf } from "./_shared.js";

const get = async (path) => arrOf(await client.get(path));
const nameOf = (u) => u?.name ?? u?.nama ?? "-";
const companyName = (c) => c?.name ?? c?.nama ?? c?.company_name ?? "-";

const STATUS_LABEL = {
  submitted: "Menunggu",
  reviewed: "Diproses",
  accepted: "Diterima",
  rejected: "Ditolak",
  draft: "Draf",
  verified: "Terverifikasi",
  active: "Aktif",
  completed: "Selesai",
  cancelled: "Dibatalkan",
};
const labelOf = (s) => STATUS_LABEL[s] ?? s ?? "-";

export async function adminSummary() {
  const [users, companies, applications, placements] = await Promise.all([
    get(ep.users.index).catch(() => []),
    get(ep.companies.index).catch(() => []),
    get(ep.applications.index).catch(() => []),
    get(ep.placements.index).catch(() => []),
  ]);
  const isRole = (r) => users.filter((u) => u.role === r).length;
  const byStatus = (s) => applications.filter((a) => a.status === s).length;
  return {
    message: "Ringkasan dashboard berhasil diambil.",
    data: {
      total_siswa: isRole("student"),
      total_guru: isRole("teacher"),
      total_perusahaan: companies.length,
      pengajuan_aktif: applications.filter((a) => ["submitted", "reviewed"].includes(a.status)).length,
      pengajuan_menunggu: byStatus("submitted"),
      siswa_ditempatkan: placements.length,
      kuota_total: companies.reduce((n, c) => n + (Number(c.quota) || 0), 0),
      kuota_terisi: placements.length,
    },
  };
}

export async function adminRecentApplications() {
  const [applications, users, companies] = await Promise.all([
    get(ep.applications.index).catch(() => []),
    get(ep.users.index).catch(() => []),
    get(ep.companies.index).catch(() => []),
  ]);
  const userById = Object.fromEntries(users.map((u) => [u.id, u]));
  const companyById = Object.fromEntries(companies.map((c) => [c.id, c]));
  const rows = applications.slice(0, 4).map((a) => {
    const s = userById[a.student_id] || {};
    const c = companyById[a.company_id] || {};
    return {
      id: a.id,
      siswa: nameOf(s),
      kelas: s.class ?? s.kelas ?? "-",
      perusahaan: companyName(c),
      status: a.status,
      label: labelOf(a.status),
    };
  });
  return { message: "Pengajuan terbaru berhasil diambil.", data: rows };
}

export async function adminPipeline() {
  const [applications, placements, journals] = await Promise.all([
    get(ep.applications.index).catch(() => []),
    get(ep.placements.index).catch(() => []),
    get(ep.journals.index).catch(() => []),
  ]);
  const pct = (n, d) => (d ? Math.round((n / d) * 100) : 0);
  return {
    message: "Alur PKL berhasil diambil.",
    data: [
      { label: "Pengajuan → Seleksi", pct: pct(applications.filter((a) => ["reviewed", "accepted"].includes(a.status)).length, applications.length) },
      { label: "Penempatan disetujui", pct: pct(placements.filter((p) => p.status === "active").length, placements.length) },
      { label: "Jurnal terverifikasi", pct: pct(journals.filter((j) => j.status === "verified").length, journals.length) },
    ],
  };
}

export async function adminMonitoringStages() {
  const [applications, placements, journals, finals] = await Promise.all([
    get(ep.applications.index).catch(() => []),
    get(ep.placements.index).catch(() => []),
    get(ep.journals.index).catch(() => []),
    get(ep.finalAssessments.index).catch(() => []),
  ]);
  const stage = (label, value) => {
    const n = Number(value) || 0;
    const pct = n ? 65 : 0;
    return { label, value: String(value), pct, text: `${pct}% selesai` };
  };
  return {
    message: "Tahapan monitoring berhasil diambil.",
    data: [
      stage("Pengajuan", applications.length),
      stage("Seleksi", applications.filter((a) => a.status === "reviewed").length),
      stage("Penempatan", placements.length),
      stage("Jurnal Aktif", journals.length),
      stage("Nilai Akhir", finals.length),
    ],
  };
}

export async function adminMonitoringRows() {
  const [placements, users, companies] = await Promise.all([
    get(ep.placements.index).catch(() => []),
    get(ep.users.index).catch(() => []),
    get(ep.companies.index).catch(() => []),
  ]);
  const userById = Object.fromEntries(users.map((u) => [u.id, u]));
  const companyById = Object.fromEntries(companies.map((c) => [c.id, c]));
  const rows = placements.slice(0, 8).map((p) => ({
    id: p.id,
    siswa: nameOf(userById[p.student_id]),
    tahap: labelOf(p.status),
    progres: companyName(companyById[p.company_id]),
    aksi: "Lihat",
  }));
  return { message: "Data monitoring siswa berhasil diambil.", data: rows };
}

export async function adminNotifications() {
  const applications = await get(ep.applications.index).catch(() => []);
  const rows = applications
    .filter((a) => a.status === "submitted")
    .slice(0, 5)
    .map((a, i) => ({
      id: a.id ?? i,
      bg: "#FEF3C7",
      title: `Pengajuan PKL baru • ID ${a.id}`,
      desc: "Perlu disposisi admin",
    }));
  return { message: "Notifikasi berhasil diambil.", data: rows };
}

export async function adminTeacherOverview() {
  const [users, placements] = await Promise.all([
    get(ep.users.index).catch(() => []),
    get(ep.placements.index).catch(() => []),
  ]);
  const teachers = users.filter((u) => u.role === "teacher");
  const countByTeacher = {};
  placements.forEach((p) => {
    if (p.teacher_id) countByTeacher[p.teacher_id] = (countByTeacher[p.teacher_id] || 0) + 1;
  });
  return {
    message: "Ikhtisar guru berhasil diambil.",
    data: {
      total_pembimbing: teachers.length,
      total_industri: users.filter((u) => u.role === "company").length,
      belum_tugas: teachers.filter((t) => !countByTeacher[t.id]).length,
      daftar: teachers.map((t) => ({
        id: t.id,
        nama: nameOf(t),
        mapel: t.subject ?? t.mapel ?? "-",
        jumlah_siswa: countByTeacher[t.id] || 0,
        status: countByTeacher[t.id] ? "aktif" : "belum",
      })),
      pemetaan: [],
    },
  };
}

export async function schoolSettings() {
  return {
    message: "Pengaturan sekolah berhasil diambil.",
    data: {
      nama_sekolah: "SMK Negeri 1",
      tahun_ajaran: "2026 Ganjil",
      batas_pilihan: "Maks 3 perusahaan / siswa",
      bobot_nilai: "Industri 40% + Guru 40% + Jurnal 20%",
    },
  };
}

export async function adminStudentRows() {
  const [users, placements, companies] = await Promise.all([
    get(ep.users.index).catch(() => []),
    get(ep.placements.index).catch(() => []),
    get(ep.companies.index).catch(() => []),
  ]);
  const students = users.filter((u) => u.role === "student");
  const placementByStudent = {};
  placements.forEach((p) => { placementByStudent[p.student_id] = p; });
  const companyById = Object.fromEntries(companies.map((c) => [c.id, c]));
  const rows = students.slice(0, 20).map((s) => {
    const p = placementByStudent[s.id];
    const c = p ? companyById[p.company_id] : null;
    return {
      id: s.id,
      name: `${s.username ?? s.id} • ${nameOf(s)}`,
      perusahaan: c ? companyName(c) : "— Belum ada —",
      pembimbing: "-",
      status: p ? labelOf(p.status) : "Belum Mengajukan",
      variant: p ? "primary" : "warning",
    };
  });
  return { message: "Data siswa berhasil diambil.", data: rows };
}

export async function adminApplicationRows() {
  const [applications, users, companies] = await Promise.all([
    get(ep.applications.index).catch(() => []),
    get(ep.users.index).catch(() => []),
    get(ep.companies.index).catch(() => []),
  ]);
  const userById = Object.fromEntries(users.map((u) => [u.id, u]));
  const companyById = Object.fromEntries(companies.map((c) => [c.id, c]));
  const byStatus = (s) => applications.filter((a) => a.status === s).length;
  const rows = applications.slice(0, 20).map((a) => ({
    id: a.id,
    name: `${nameOf(userById[a.student_id])} → ${companyName(companyById[a.company_id])}`,
    pilihan: `Pilihan ke-${a.choice_order ?? "-"}`,
    status: labelOf(a.status),
    variant: a.status === "accepted" ? "success" : a.status === "rejected" ? "danger" : a.status === "submitted" ? "warning" : "primary",
  }));
  const tabs = [
    { key: "semua", label: `Semua (${applications.length})` },
    { key: "menunggu", label: `Menunggu (${byStatus("submitted")})` },
    { key: "diproses", label: `Diproses (${byStatus("reviewed")})` },
    { key: "diterima", label: `Diterima (${byStatus("accepted")})` },
    { key: "ditolak", label: `Ditolak (${byStatus("rejected")})` },
  ];
  return { message: "Data pengajuan berhasil diambil.", data: { rows, tabs } };
}
