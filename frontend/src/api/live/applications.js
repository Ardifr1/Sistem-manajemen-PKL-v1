/** live/applications.js — pkl-applications + application-documents + application-reviews. */
import { client } from "../client.js";
import { ep } from "../endpoints.js";
import { liveCrud } from "./_shared.js";

export const { index, show, store, update, destroy } = liveCrud(ep.applications);
export const documentsApi = liveCrud(ep.applicationDocuments);
export const reviewsApi = liveCrud(ep.applicationReviews);

/**
 * Pilihan final siswa: satu tawaran dipilih, tawaran lain pada periode yang
 * sama ditutup oleh backend (bukan hanya disembunyikan di UI).
 */
export async function choose(id) {
  return client.post(ep.applications.choose(id));
}
