/** live/users.js — apiResource users (khusus admin). */
import { client } from "../client.js";
import { ep } from "../endpoints.js";
import { liveCrud } from "./_shared.js";

export const { index, show, store, update, destroy } = liveCrud(ep.users);

export async function resetRequests() {
  return client.get(ep.users.resetRequests);
}

export async function resolveResetRequest(id) {
  return client.patch(ep.users.resolveResetRequest(id));
}
