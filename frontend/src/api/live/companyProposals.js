/** live/companyProposals.js — pengajuan perusahaan NONMITRA oleh siswa. */
import { client } from "../client.js";
import { ep } from "../endpoints.js";
import { liveCrud } from "./_shared.js";

export const { index, show } = liveCrud(ep.companyProposals);

/**
 * Kirim pengajuan perusahaan. `payload` boleh FormData (bila melampirkan bukti)
 * atau objek biasa.
 */
export async function store(payload) {
  return client.post(ep.companyProposals.store, payload);
}

/**
 * Verifikasi pengajuan (khusus admin sekolah).
 * @param {number|string} companyId
 * @param {{action: "approve"|"reject", reason?: string}} payload
 */
export async function verify(companyId, payload) {
  return client.patch(ep.companyProposals.verify(companyId), payload);
}
