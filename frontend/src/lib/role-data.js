/**
 * Helper data bersama untuk halaman guru & industri.
 * Menggabungkan penempatan + nama siswa + nama perusahaan di sisi klien,
 * karena backend me-return relasi nested hanya di mode live.
 */
import {
  placementsApi,
  journalsApi,
  usersApi,
  companiesApi,
  companyMembershipsApi,
  authApi,
} from "../api/index.js";

const arr = (res) => res?.data ?? [];

/** Penempatan + relasi student & company (pakai nested bila ada). */
export async function getEnrichedPlacements() {
  const rows = arr(await placementsApi.index());

  const needUsers = rows.some((p) => !p.student);
  const needCompanies = rows.some((p) => !p.company);

  // Ambil paralel, bukan satu per satu.
  const [uRes, cRes] = await Promise.all([
    needUsers
      ? usersApi.index({ role: "student" }).catch(() => ({ data: [] }))
      : Promise.resolve({ data: [] }),
    needCompanies
      ? companiesApi.index().catch(() => ({ data: [] }))
      : Promise.resolve({ data: [] }),
  ]);

  const users = Object.fromEntries(arr(uRes).map((u) => [u.id, u]));
  const companies = Object.fromEntries(arr(cRes).map((c) => [c.id, c]));

  return rows.map((p) => ({
    ...p,
    student: p.student || users[p.student_id] || { name: `Siswa #${p.student_id}` },
    company: p.company || companies[p.company_id] || { name: "-" },
  }));
}

/** Semua jurnal dari daftar penempatan — diambil paralel, bukan antri. */
export async function getJournalsForPlacements(placements) {
  const results = await Promise.all(
    placements.map(async (p) => {
      try {
        const res = await journalsApi.index({ placement_id: p.id });
        return arr(res).map((j) => ({ ...j, placement: p }));
      } catch {
        return [];
      }
    })
  );
  return results
    .flat()
    .sort((a, b) =>
      String(b.journal_date || "").localeCompare(String(a.journal_date || ""))
    );
}

/** company_supervisor id milik user pembimbing yang sedang login. */
export async function getMySupervisorId() {
  const user = authApi.currentUser();
  if (!user) return null;
  try {
    const res = await companiesApi.supervisorsApi.index();
    const sup = arr(res).find((s) => String(s.user_id) === String(user.id));
    return sup?.id ?? null;
  } catch {
    return null;
  }
}

/** company_id milik user industri yang sedang login. */
export async function getMyCompanyId() {
  const user = authApi.currentUser();
  if (!user) return null;
  // Jalur lama: tautan company-supervisors (akun dari pengajuan perusahaan
  // & pembimbing).
  try {
    const res = await companiesApi.supervisorsApi.index();
    const sup = arr(res).find((s) => String(s.user_id) === String(user.id));
    if (sup?.company_id) return sup.company_id;
  } catch {
    /* lanjut ke keanggotaan */
  }
  // Jalur admin: akun yang ditautkan lewat keanggotaan perusahaan tidak
  // punya baris supervisors — resolusi lewat keanggotaan aktif miliknya.
  try {
    const res = await companyMembershipsApi.index();
    const mine = arr(res).find(
      (m) => String(m.user_id) === String(user.id) && m.status === "active",
    );
    return mine?.company_id ?? null;
  } catch {
    return null;
  }
}

export const STATUS_LABEL = {
  draft: "Draft",
  submitted: "Menunggu",
  verified: "Disetujui",
  needs_revision: "Revisi",
  active: "AKTIF",
  scheduled: "TERJADWAL",
  completed: "SELESAI",
  cancelled: "Batal",
};

export function statusLabel(s) {
  return STATUS_LABEL[s] || s || "-";
}

const BULAN = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];

/** "2026-08-01T00:00:00.000000Z" -> "1 Agu 2026" */
export function formatDate(iso) {
  if (!iso) return "-";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return String(iso);
  return `${d.getDate()} ${BULAN[d.getMonth()]} ${d.getFullYear()}`;
}

/** "2026-08-01" -> "1 Agu" (ala mockup: "18 Jun") */
export function formatDateShort(iso) {
  if (!iso) return "-";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return String(iso);
  return `${d.getDate()} ${BULAN[d.getMonth()]}`;
}
