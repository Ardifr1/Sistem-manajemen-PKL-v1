/**
 * mock/placements.js — apiResource pkl-placements
 * Kolom backend: student_id, company_id, pkl_period_id, application_id,
 *   start_date, end_date, status (active/completed/cancelled).
 */
import { delay, ok, nextId } from "./_shared.js";

const db = [
  { id: 1, student_id: 3, company_id: 1, pkl_period_id: 1, application_id: 1, start_date: "2026-08-01", end_date: "2026-10-31", status: "active" },
  { id: 2, student_id: 6, company_id: 2, pkl_period_id: 1, application_id: 3, start_date: "2026-08-01", end_date: "2026-10-31", status: "active" },
  { id: 3, student_id: 8, company_id: 5, pkl_period_id: 1, application_id: 5, start_date: "2026-01-05", end_date: "2026-03-30", status: "completed" },
];

export async function index(params = {}) {
  await delay();
  let rows = [...db];
  for (const k of ["student_id", "company_id", "pkl_period_id", "status"]) {
    if (params[k] !== undefined) rows = rows.filter((r) => String(r[k]) === String(params[k]));
  }
  return ok("Data penempatan PKL berhasil diambil.", rows);
}

export async function show(id) {
  await delay();
  const row = db.find((r) => r.id === Number(id));
  if (!row) throw Object.assign(new Error("Penempatan tidak ditemukan."), { status: 404 });
  return ok("Data penempatan PKL berhasil diambil.", row);
}

export async function store(payload) {
  await delay();
  const row = { id: nextId(), status: "active", ...payload };
  db.push(row);
  return ok("Penempatan PKL berhasil ditambahkan.", row);
}

export async function update(id, payload) {
  await delay();
  const row = db.find((r) => r.id === Number(id));
  if (!row) throw Object.assign(new Error("Penempatan tidak ditemukan."), { status: 404 });
  Object.assign(row, payload);
  return ok("Penempatan PKL berhasil diperbarui.", row);
}

/**
 * Perusahaan menetapkan/mengganti pembimbing industri (hanya supervisor_id),
 * meniru PATCH /pkl-placements/{id}/supervisor.
 */
export async function assignSupervisor(id, supervisorId) {
  await delay();
  const row = db.find((r) => r.id === Number(id));
  if (!row) throw Object.assign(new Error("Penempatan tidak ditemukan."), { status: 404 });
  row.supervisor_id = supervisorId ?? null;
  return ok("Pembimbing industri berhasil ditugaskan.", row);
}

export async function destroy(id) {
  await delay();
  const i = db.findIndex((r) => r.id === Number(id));
  if (i < 0) throw Object.assign(new Error("Penempatan tidak ditemukan."), { status: 404 });
  db.splice(i, 1);
  return ok("Penempatan PKL berhasil dihapus.", null);
}
