/**
 * mock/assessments.js — apiResource assessment-components, assessments,
 * final-assessments.
 * Kolom assessment-components: name, description, weight, is_active.
 * Kolom assessments: placement_id, component_id, score, note.
 * Kolom final-assessments: placement_id, final_score, status, finalized_by,
 *   finalized_at.
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
  { id: 1, name: "Kedisiplinan", weight: 20, is_active: true },
  { id: 2, name: "Sikap", weight: 15, is_active: true },
  { id: 3, name: "Tanggung Jawab", weight: 20, is_active: true },
  { id: 4, name: "Komunikasi", weight: 15, is_active: true },
  { id: 5, name: "Kemampuan Kerja", weight: 20, is_active: true },
  { id: 6, name: "Perkembangan Kompetensi", weight: 10, is_active: true },
];
export const componentsApi = crud(components, "Komponen penilaian", "komponen penilaian");
export const finalAssessmentsApi = {
  ...crud([], "Penilaian akhir", "penilaian akhir"),
  calculate: async (placement_id) => {
    void placement_id;
    return { message: "Mode mock: perhitungan otomatis tidak tersedia.", data: null };
  },
  publish: async (id) => {
    void id;
    return { message: "Mode mock: publikasi tidak tersedia.", data: null };
  },
  unpublish: async (id, note) => {
    void id; void note;
    return { message: "Mode mock: pembatalan publikasi tidak tersedia.", data: null };
  },
};
