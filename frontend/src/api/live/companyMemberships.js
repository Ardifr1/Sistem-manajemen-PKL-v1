/** live/companyMemberships.js — keanggotaan perusahaan eksplisit (PRD bagian C). */
import { ep } from "../endpoints.js";
import { liveCrud } from "./_shared.js";

export const { index, show, store, update, destroy } = liveCrud(ep.companyMemberships);

export const CAPABILITIES = [
  { value: "company_admin", label: "Admin Perusahaan" },
  { value: "review_applications", label: "Meninjau Pengajuan" },
  { value: "verify_journals", label: "Verifikasi Jurnal" },
];
