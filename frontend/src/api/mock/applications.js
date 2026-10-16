/**
 * mock/applications.js — apiResource pkl-applications, application-documents,
 * application-reviews.
 * Kolom pkl-applications: student_id, company_id, pkl_period_id, choice_order,
 *   student_note, status.
 * Kolom application-documents: application_id, document_name, document_type,
 *   file_path, status.
 * Kolom application-reviews: application_id, reviewer_id, reviewer_role,
 *   status, note, reviewed_at.
 */
import { delay, ok, nextId } from "./_shared.js";

const db = [
  { id: 1, student_id: 3, company_id: 1, pkl_period_id: 1, choice_order: 1, student_note: "Sesuai jurusan RPL", status: "submitted" },
  { id: 2, student_id: 3, company_id: 4, pkl_period_id: 1, choice_order: 2, student_note: "", status: "accepted" },
  { id: 3, student_id: 6, company_id: 2, pkl_period_id: 1, choice_order: 1, student_note: "", status: "reviewed" },
  { id: 4, student_id: 7, company_id: 3, pkl_period_id: 1, choice_order: 1, student_note: "", status: "rejected" },
  { id: 5, student_id: 8, company_id: 5, pkl_period_id: 1, choice_order: 1, student_note: "", status: "accepted" },
];

const documents = [
  { id: 1, application_id: 1, document_name: "CV Rina Amelia", document_type: "cv", file_path: "/storage/cv-rina.pdf", status: "verified" },
  { id: 2, application_id: 1, document_name: "Portofolio", document_type: "portfolio", file_path: "/storage/portofolio-rina.pdf", status: "pending" },
];

const reviews = [
  { id: 1, application_id: 2, reviewer_id: 4, reviewer_role: "company", status: "accepted", note: "Lolos seleksi berkas", reviewed_at: "2026-08-20 10:00:00" },
  { id: 2, application_id: 4, reviewer_id: 4, reviewer_role: "company", status: "rejected", note: "Kuota penuh", reviewed_at: "2026-08-21 14:00:00" },
];

function crud(store, singular, plural) {
  return {
    async index(params = {}) {
      await delay();
      let rows = [...store];
      for (const k of ["student_id", "company_id", "pkl_period_id", "application_id", "status"]) {
        if (params[k] !== undefined) rows = rows.filter((r) => String(r[k]) === String(params[k]));
      }
      return ok(`Data ${plural} berhasil diambil.`, rows);
    },
    async show(id) {
      await delay();
      const row = store.find((r) => r.id === Number(id));
      if (!row) throw Object.assign(new Error(`${singular} tidak ditemukan.`), { status: 404 });
      return ok(`Data ${singular} berhasil diambil.`, row);
    },
    async store(payload) {
      await delay();
      const row = { id: nextId(), ...payload };
      store.push(row);
      return ok(`${singular} berhasil ditambahkan.`, row);
    },
    async update(id, payload) {
      await delay();
      const row = store.find((r) => r.id === Number(id));
      if (!row) throw Object.assign(new Error(`${singular} tidak ditemukan.`), { status: 404 });
      Object.assign(row, payload);
      return ok(`${singular} berhasil diperbarui.`, row);
    },
    async destroy(id) {
      await delay();
      const i = store.findIndex((r) => r.id === Number(id));
      if (i < 0) throw Object.assign(new Error(`${singular} tidak ditemukan.`), { status: 404 });
      store.splice(i, 1);
      return ok(`${singular} berhasil dihapus.`, null);
    },
  };
}

export const { index, show, store, update, destroy } = crud(db, "Pengajuan PKL", "pengajuan PKL");
export const documentsApi = crud(documents, "Dokumen pengajuan", "dokumen pengajuan");
export const reviewsApi = crud(reviews, "Review pengajuan", "review pengajuan");

/**
 * Pilihan final siswa: satu tawaran dipilih, tawaran lain pada periode yang
 * sama ditutup (status rejected) — meniru PklApplicationController::choose.
 */
export async function choose(id) {
  await delay();
  const row = db.find((r) => r.id === Number(id));
  if (!row) throw Object.assign(new Error("Pengajuan tidak ditemukan."), { status: 404 });
  if (row.status !== "accepted") {
    throw Object.assign(
      new Error("Hanya pengajuan yang sudah diterima perusahaan yang dapat dipilih sebagai tujuan PKL."),
      { status: 422 }
    );
  }

  let closed = 0;
  for (const other of db) {
    if (
      other.id !== row.id &&
      other.student_id === row.student_id &&
      other.pkl_period_id === row.pkl_period_id &&
      other.status === "accepted"
    ) {
      other.status = "rejected";
      closed += 1;
    }
  }

  return { ...ok("Pilihan final tersimpan.", row), closed_offers: closed };
}
