/**
 * Helper notifikasi sederhana (non-realtime, dihitung dari data yang ada).
 * Setiap builder best-effort: gagal diam-diam -> array kosong.
 * Bentuk item: { icon (kelas FA), tone (blue|amber|red|green), text, time }
 */
import {
  journalsApi,
  applicationsApi,
  placementsApi,
  assessmentsApi,
  usersApi,
} from "../api/index.js";
import {
  getEnrichedPlacements,
  getJournalsForPlacements,
  getMyCompanyId,
} from "./role-data.js";

const arr = (res) => res?.data ?? [];
const safe = async (fn) => {
  try {
    return await fn();
  } catch {
    return [];
  }
};

/* ---------------- siswa ---------------- */
async function studentNotifs({ placement } = {}) {
  const out = [];
  if (placement?.id) {
    const journals = await safe(() => journalsApi.index({ placement_id: placement.id }).then(arr));
    const revisi = journals.filter((j) => j.status === "needs_revision");
    if (revisi.length) {
      out.push({
        icon: "fa-file-pen",
        tone: "amber",
        text: `${revisi.length} jurnal perlu direvisi`,
        time: "Segera perbaiki & kirim ulang",
        to: "jurnal",
      });
    }
  }
  const apps = await safe(() => applicationsApi.index().then(arr));
  const decided = apps
    .filter((a) => ["accepted", "approved", "rejected"].includes(a.status))
    .sort((a, b) => String(b.updated_at || "").localeCompare(String(a.updated_at || "")))[0];
  if (decided) {
    const co = decided.company?.name || "perusahaan";
    out.push({
      icon: decided.status === "rejected" ? "fa-circle-xmark" : "fa-circle-check",
      tone: decided.status === "rejected" ? "red" : "green",
      text: `Pengajuan ke ${co} ${decided.status === "rejected" ? "ditolak" : "diterima"}`,
      time: "Pengajuan PKL",
      to: "pengajuan",
    });
  }
  return out;
}

/* ---------------- guru ---------------- */
async function teacherNotifs() {
  const out = [];
  const placements = await safe(getEnrichedPlacements);
  if (placements.length) {
    const journals = await safe(() => getJournalsForPlacements(placements));
    const batas = Date.now() - 3 * 864e5;
    const sepiList = [];
    for (const p of placements) {
      const js = journals.filter((j) => String(j.placement_id) === String(p.id));
      const terakhir = js[0]?.journal_date;
      if (!terakhir || new Date(terakhir).getTime() < batas) {
        sepiList.push(p.student?.name || p.student_name || `Siswa #${p.id}`);
      }
    }
    if (sepiList.length) {
      const nama = sepiList.length <= 3
        ? sepiList.join(", ")
        : `${sepiList.slice(0, 3).join(", ")} +${sepiList.length - 3} lainnya`;
      out.push({
        icon: "fa-bell",
        tone: "red",
        text: `${sepiList.length} siswa belum jurnal 3 hari`,
        time: nama,
        to: "siswa",
      });
    }
  }
  const assessments = await safe(() => assessmentsApi.index().then(arr));
  const evaluasi = assessments.filter((a) => a.assessor_role === "company");
  if (evaluasi.length) {
    out.push({
      icon: "fa-star",
      tone: "green",
      text: `${evaluasi.length} evaluasi masuk dari perusahaan`,
      time: "Lihat penilaian",
      to: "penilaian",
    });
  }
  return out;
}

/* ---------------- perusahaan ---------------- */
async function companyNotifs() {
  const out = [];
  const apps = await safe(() => applicationsApi.index().then(arr));
  const baru = apps.filter((a) => ["pending", "submitted"].includes(a.status));
  if (baru.length) {
    out.push({
      icon: "fa-inbox",
      tone: "blue",
      text: `${baru.length} pengajuan magang baru`,
      time: "Perlu ditinjau",
        to: "pengajuan",
    });
  }
  const companyId = await safe(getMyCompanyId);
  const placements = await safe(() => placementsApi.index().then(arr));
  const milik = companyId
    ? placements.filter((p) => String(p.company_id) === String(companyId))
    : placements;
  let menunggu = 0;
  for (const p of milik.slice(0, 30)) {
    const js = await safe(() => journalsApi.index({ placement_id: p.id }).then(arr));
    menunggu += js.filter((j) => ["pending", "submitted"].includes(j.status)).length;
  }
  if (menunggu) {
    out.push({
      icon: "fa-book-open",
      tone: "amber",
      text: `${menunggu} jurnal menunggu verifikasi`,
      time: "Segera verifikasi",
    });
  }
  return out;
}

/* ---------------- pembimbing industri ---------------- */
async function supervisorNotifs() {
  const out = [];
  const placements = await safe(getEnrichedPlacements);
  let menunggu = 0;
  for (const p of placements.slice(0, 30)) {
    const js = await safe(() => journalsApi.index({ placement_id: p.id }).then(arr));
    menunggu += js.filter((j) => ["pending", "submitted"].includes(j.status)).length;
  }
  if (menunggu) {
    out.push({
      icon: "fa-book-open",
      tone: "amber",
      text: `${menunggu} jurnal menunggu verifikasi`,
      time: "Siswa bimbinganmu",
        to: "jurnal",
    });
  }
  return out;
}

/* ---------------- admin ---------------- */
async function adminNotifs() {
  const out = [];
  const resets = await safe(() => usersApi.resetRequests().then(arr));
  if (resets.length) {
    out.push({
      icon: "fa-key",
      tone: "red",
      text: `${resets.length} permintaan reset password`,
      time: "Kelola Pengguna",
      to: "pengguna",
    });
  }
  const apps = await safe(() => applicationsApi.index().then(arr));
  const pending = apps.filter((a) => ["pending", "submitted"].includes(a.status));
  if (pending.length) {
    out.push({
      icon: "fa-hourglass-half",
      tone: "blue",
      text: `${pending.length} pengajuan menunggu persetujuan`,
      time: "Persetujuan PKL",
        to: "persetujuan",
    });
  }
  const journals = await safe(() => journalsApi.index().then(arr));
  const menunggu = journals.filter((j) => ["pending", "submitted"].includes(j.status));
  if (menunggu.length) {
    out.push({
      icon: "fa-building",
      tone: "amber",
      text: `${menunggu} jurnal belum diverifikasi perusahaan`,
      time: "Monitoring jurnal",
      to: "jurnal",
    });
  }
  return out;
}

/**
 * @param {string} role  student | teacher | company | supervisor | admin
 * @param {Object} ctx   { user, placement }
 */
export async function getNotifications(role, ctx = {}) {
  try {
    switch (role) {
      case "student":
        return await studentNotifs(ctx);
      case "teacher":
        return await teacherNotifs(ctx);
      case "company":
        return await companyNotifs(ctx);
      case "supervisor":
        return await supervisorNotifs(ctx);
      case "admin":
        return await adminNotifs(ctx);
      default:
        return [];
    }
  } catch {
    return [];
  }
}
