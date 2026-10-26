/**
 * mock/ai.js — AI Journal Assistant untuk mode mock (tanpa backend).
 * Meniru bentuk respons backend; bukan panggilan model nyata.
 */
import { delay, ok } from "./_shared.js";

const TOOLS = ["Laravel", "Postman", "MySQL", "Git", "Figma", "React", "PHP", "JavaScript"];

export async function review(draft) {
  await delay();

  const text = String(draft || "").trim();
  const lower = text.toLowerCase();
  const detected = TOOLS.filter((tool) => lower.includes(tool.toLowerCase()));
  const hasOutcome = /(berhasil|selesai|hasil|mengembalikan|200|ok)/i.test(text);

  const missing = [];
  if (detected.length === 0) missing.push("alat atau teknologi yang dipakai");
  if (!hasOutcome) missing.push("hasil atau kondisi akhir pekerjaan");

  const questions = [];
  if (detected.length === 0) {
    questions.push({
      id: "alat",
      question: "Alat atau teknologi apa yang kamu pakai?",
      hint: "Pilih semua yang sesuai",
      options: ["Laravel", "Postman", "MySQL", "Git", "React"],
    });
  }
  if (!hasOutcome) {
    questions.push({
      id: "hasil",
      question: "Apa hasil akhir kegiatan ini?",
      hint: "Contoh: fitur berjalan, pengujian lulus",
      options: ["Fitur berjalan", "Pengujian lulus", "Masih dikerjakan"],
    });
  }

  const revised = /[.!?]$/.test(text) ? text : `${text}.`;

  return ok("Saran AI berhasil dibuat (mode mock).", {
    summary: text.slice(0, 200),
    missing,
    questions,
    revised_activity: revised,
    model: "mock",
  });
}
