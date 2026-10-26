/** live/placements.js — apiResource pkl-placements. */
import { client } from "../client.js";
import { ep } from "../endpoints.js";
import { liveCrud } from "./_shared.js";

export const { index, show, store, update, destroy } = liveCrud(ep.placements);

/**
 * Perusahaan menetapkan/mengganti pembimbing industri pada penempatannya.
 * Endpoint khusus ini hanya menulis supervisor_id.
 */
export async function assignSupervisor(placementId, supervisorId) {
  return client.patch(ep.placements.assignSupervisor(placementId), {
    supervisor_id: supervisorId,
  });
}
