/**
 * mock/companyProposals.js — pengajuan perusahaan NONMITRA (PRD bagian D).
 *
 * Kolom mengikuti backend: name, industry, address, contact_person, phone,
 * email, description, proof_path, verification_status, verification_note,
 * submitted_by, is_partner, is_active.
 */
import { delay, ok, nextId } from "./_shared.js";

const db = [
  {
    id: 9001,
    name: "PT Cahaya Tekstil Mandiri",
    industry: "Tekstil",
    address: "Jl. Raya Cimahi No. 88, Bandung",
    contact_person: "Ibu Ratna",
    phone: "0812-3344-5566",
    email: "hrd@cahayatekstil.test",
    description: "Produsen tekstil yang mengajukan kerja sama PKL.",
    proof_path: null,
    verification_status: "pending_school_verification",
    verification_note: null,
    submitted_by: 3,
    is_partner: false,
    is_active: false,
    student_quota: 0,
  },
];

export async function index(params = {}) {
  await delay();
  let rows = [...db];
  if (params.verification_status) {
    rows = rows.filter((r) => r.verification_status === params.verification_status);
  }
  return ok("Data pengajuan perusahaan berhasil diambil.", rows);
}

export async function show(id) {
  await delay();
  const row = db.find((r) => r.id === Number(id));
  if (!row) throw Object.assign(new Error("Pengajuan tidak ditemukan."), { status: 404 });
  return ok("Data pengajuan perusahaan berhasil diambil.", row);
}

export async function store(payload) {
  await delay();
  const data = typeof FormData !== "undefined" && payload instanceof FormData
    ? Object.fromEntries(payload.entries())
    : payload;

  const row = {
    id: nextId(),
    description: null,
    proof_path: null,
    verification_note: null,
    ...data,
  };

  // Status mitra & verifikasi hanya boleh berubah setelah verifikasi admin —
  // nilai dari payload tidak boleh menaikkannya (mirip validasi backend).
  Object.assign(row, {
    verification_status: "pending_school_verification",
    is_partner: false,
    is_active: false,
    student_quota: 0,
  });

  db.push(row);
  return ok("Pengajuan perusahaan berhasil dikirim dan menunggu verifikasi sekolah.", row);
}

export async function verify(companyId, payload = {}) {
  await delay();
  const row = db.find((r) => r.id === Number(companyId));
  if (!row) throw Object.assign(new Error("Pengajuan tidak ditemukan."), { status: 404 });

  if (row.verification_status !== "pending_school_verification") {
    throw Object.assign(new Error("Hanya perusahaan berstatus menunggu verifikasi yang dapat diproses."), { status: 422 });
  }

  const approved = payload.action === "approve";

  if (!approved && !payload.reason) {
    throw Object.assign(new Error("Alasan penolakan wajib diisi."), { status: 422 });
  }

  Object.assign(row, {
    verification_status: approved ? "verified" : "rejected",
    is_partner: approved,
    is_active: approved,
    verification_note: payload.reason ?? null,
  });

  return ok(
    approved
      ? "Perusahaan disetujui dan didaftarkan sebagai mitra."
      : "Pengajuan perusahaan ditolak.",
    row
  );
}
