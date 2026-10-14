/**
 * mock/assessments.js — apiResource assessment-components, assessments,
 * final-assessments.
 * Kolom assessment-components: name, description, owner_type, weight, is_active.
 * Kolom assessments: placement_id, component_id, score, note.
 * Kolom final-assessments: placement_id, final_score, status, finalized_by,
 *   finalized_at, published_by, published_at, unpublish_reason.
 */
import { delay, ok, nextId } from "./_shared.js";


const assessments = [
  { id: 1, placement_id: 3, component_id: 1, score: 90, note: "Selalu tepat waktu" },
  { id: 2, placement_id: 3, component_id: 2, score: 85, note: "Baik" },
  { id: 3, placement_id: 3, component_id: 3, score: 88, note: "" },
  { id: 4, placement_id: 3, component_id: 4, score: 82, note: "" },
];


function crud(store, singular, plural) {
  return {
    async index(params = {}) {
      await delay();
      let rows = [...store];
      for (const k of ["placement_id", "component_id", "status", "is_active"]) {
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

export const { index, show, store, update, destroy } = crud(assessments, "Penilaian", "penilaian");

const components = [
  { id: 1, name: "Kedisiplinan", owner_type: "industri", weight: 15, is_active: true },
  { id: 2, name: "Sikap Kerja", owner_type: "industri", weight: 15, is_active: true },
  { id: 3, name: "Komunikasi", owner_type: "industri", weight: 10, is_active: true },
  { id: 4, name: "Kemampuan Kerja", owner_type: "industri", weight: 20, is_active: true },
  { id: 5, name: "Perkembangan Kompetensi", owner_type: "industri", weight: 10, is_active: true },
  { id: 6, name: "Laporan & Jurnal", owner_type: "guru", weight: 15, is_active: true },
  { id: 7, name: "Presentasi & Pemahaman", owner_type: "guru", weight: 15, is_active: true },
];
export const componentsApi = crud(components, "Komponen penilaian", "komponen penilaian");
const finals = [
  { id: 1, placement_id: 3, final_score: 85.5, status: "published", finalized_by: 2, finalized_at: "2026-10-01T10:00:00Z", published_by: 1, published_at: "2026-10-02T10:00:00Z", unpublish_reason: null },
];
const finalsBase = crud(finals, "Penilaian akhir", "penilaian akhir");
export const finalAssessmentsApi = {
  ...finalsBase,
  async calculate(placement_id) {
    void placement_id;
    return { message: "Mode mock: perhitungan otomatis tidak tersedia.", data: null };
  },
  async publish(id) {
    await delay();
    const row = finals.find((r) => r.id === Number(id));
    if (!row) throw Object.assign(new Error("Penilaian akhir tidak ditemukan."), { status: 404 });
    Object.assign(row, { status: "published", published_at: new Date().toISOString(), unpublish_reason: null });
    return ok("Nilai akhir berhasil dipublikasikan.", row);
  },
  async unpublish(id, reason) {
    await delay();
    const row = finals.find((r) => r.id === Number(id));
    if (!row) throw Object.assign(new Error("Penilaian akhir tidak ditemukan."), { status: 404 });
    if (!reason || String(reason).trim().length < 5) {
      throw Object.assign(new Error("Alasan pembatalan wajib diisi (min. 5 karakter)."), { status: 422 });
    }
    Object.assign(row, { status: "draft", published_at: null, unpublish_reason: reason });
    return ok("Publikasi nilai akhir berhasil dibatalkan.", row);
  },
};

export const auditLogsApi = crud([], "Audit log", "audit log");
export const finalsApi = finalAssessmentsApi;
