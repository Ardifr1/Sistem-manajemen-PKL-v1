/**
 * mock/companyMemberships.js — keanggotaan perusahaan eksplisit (PRD bagian C).
 *
 * Kolom mengikuti backend: user_id, company_id, status, capabilities,
 * granted_by, granted_at, revoked_at.
 */
import { delay, ok, nextId } from "./_shared.js";

const db = [
  {
    id: 8001,
    user_id: 4,
    company_id: 1,
    status: "active",
    capabilities: ["company_admin", "review_applications", "verify_journals"],
    granted_by: 1,
    granted_at: "2026-10-01T08:00:00.000000Z",
    revoked_at: null,
  },
];

export const CAPABILITIES = [
  { value: "company_admin", label: "Admin Perusahaan" },
  { value: "review_applications", label: "Meninjau Pengajuan" },
  { value: "verify_journals", label: "Verifikasi Jurnal" },
];

export async function index(params = {}) {
  await delay();
  let rows = [...db];
  if (params.company_id) rows = rows.filter((r) => r.company_id === Number(params.company_id));
  if (params.user_id) rows = rows.filter((r) => r.user_id === Number(params.user_id));
  if (params.status) rows = rows.filter((r) => r.status === params.status);
  return ok("Data keanggotaan perusahaan berhasil diambil.", rows);
}

export async function show(id) {
  await delay();
  const row = db.find((r) => r.id === Number(id));
  if (!row) throw Object.assign(new Error("Keanggotaan tidak ditemukan."), { status: 404 });
  return ok("Data keanggotaan perusahaan berhasil diambil.", row);
}

export async function store(payload) {
  await delay();
  const existing = db.find(
    (r) => r.user_id === Number(payload.user_id) && r.company_id === Number(payload.company_id)
  );

  if (existing) {
    Object.assign(existing, {
      status: payload.status ?? "active",
      capabilities: payload.capabilities ?? existing.capabilities,
      granted_at: new Date().toISOString(),
      revoked_at: null,
    });
    return ok("Keanggotaan perusahaan berhasil disimpan.", existing);
  }

  const row = {
    id: nextId(),
    status: "active",
    capabilities: [],
    granted_by: 1,
    granted_at: new Date().toISOString(),
    revoked_at: null,
    ...payload,
  };
  db.push(row);
  return ok("Keanggotaan perusahaan berhasil disimpan.", row);
}

export async function update(id, payload) {
  await delay();
  const row = db.find((r) => r.id === Number(id));
  if (!row) throw Object.assign(new Error("Keanggotaan tidak ditemukan."), { status: 404 });
  Object.assign(row, payload, {
    revoked_at: (payload.status ?? row.status) === "active" ? null : new Date().toISOString(),
  });
  return ok("Keanggotaan perusahaan berhasil diperbarui.", row);
}

export async function destroy(id) {
  await delay();
  const i = db.findIndex((r) => r.id === Number(id));
  if (i < 0) throw Object.assign(new Error("Keanggotaan tidak ditemukan."), { status: 404 });
  db.splice(i, 1);
  return ok("Keanggotaan perusahaan berhasil dihapus.", null);
}
