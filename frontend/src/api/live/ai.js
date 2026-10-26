/** live/ai.js — AI Journal Assistant (diproses backend, bukan di browser). */
import { client } from "../client.js";
import { ep } from "../endpoints.js";

/**
 * Kirim draf jurnal untuk diperiksa AI.
 * Respons: { message, data: { summary, missing[], questions[], revised_activity, model } }
 */
export function review(draft) {
  return client.post(ep.ai.journalAssistant, { draft });
}
