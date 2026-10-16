/**
 * mock/users.js — apiResource users (khusus role admin di backend).
 * Kolom backend: name, username, email, password, role, is_active.
 */
import { delay, ok, nextId } from "./_shared.js";

const db = [
  { id: 1, name: "Rina Amelia", username: "rina.amelia", email: "rina@smkn1.sch.id", role: "student", is_active: true },
  { id: 2, name: "Bagas Pratama", username: "bagas.pratama", email: "bagas@smkn1.sch.id", role: "student", is_active: true },
  { id: 3, name: "Hartono, S.Kom", username: "hartono", email: "hartono@smkn1.sch.id", role: "teacher", is_active: true },
  { id: 4, name: "Sari Wulandari, S.T.", username: "sari.wulandari", email: "sari@smkn1.sch.id", role: "teacher", is_active: true },
  { id: 5, name: "PT Maju Jaya Abadi", username: "majujaya", email: "hrd@majujaya.id", role: "company", is_active: true },
  { id: 6, name: "Admin TU", username: "admin.tu", email: "tu@smkn1.sch.id", role: "admin", is_active: true },
  { id: 7, name: "Dimas Saputra", username: "dimas.saputra", email: "dimas@smkn1.sch.id", role: "student", is_active: false },
];

const ROLE_LABEL = { student: "Siswa", teacher: "Guru", company: "Perusahaan", supervisor: "Pembimbing", admin: "Admin" };

export async function index(params = {}) {
  await delay();
  let rows = db.map((u) => ({ ...u, password: undefined }));
  if (params.role) rows = rows.filter((r) => r.role === params.role);
  if (params.q) {
    const q = params.q.toLowerCase();
    rows = rows.filter((r) => r.name.toLowerCase().includes(q) || r.email.toLowerCase().includes(q));
  }
  return ok("Data pengguna berhasil diambil.", rows);
}

export async function show(id) {
  await delay();
  const row = db.find((r) => r.id === Number(id));
  if (!row) throw Object.assign(new Error("Pengguna tidak ditemukan."), { status: 404 });
  const { password, ...safe } = row;
  return ok("Data pengguna berhasil diambil.", safe);
}

export async function store(payload) {
  await delay();
  if (db.some((u) => u.email === payload.email)) {
    const err = new Error("Email sudah digunakan.");
    err.status = 422;
    err.validationErrors = { email: ["Email sudah digunakan."] };
    throw err;
  }
  const row = {
    id: nextId(),
    // Username dibuat otomatis dari email — tidak lagi diinput admin.
    username: payload.email.split("@")[0].toLowerCase().replace(/[^a-z0-9.]/g, ""),
    is_active: true,
    ...payload,
    password: undefined,
  };
  db.push({ ...row, password: "hashed" });

  const res = ok("Pengguna berhasil ditambahkan.", row);
  // Meniru backend: akun tanpa password manual memakai tautan aktivasi.
  if (!payload.password) {
    res.delivery = payload.phone
      ? { email: { status: "not_configured", message: "Mode mock — email tidak dikirim." },
          whatsapp: { status: "not_configured", message: "Mode mock — WhatsApp tidak dikirim." } }
      : { email: { status: "not_configured", message: "Mode mock — email tidak dikirim." },
          whatsapp: { status: "failed", message: "Nomor HP penerima tidak tersedia." } };
  }
  return res;
}

export async function update(id, payload) {
  await delay();
  const row = db.find((r) => r.id === Number(id));
  if (!row) throw Object.assign(new Error("Pengguna tidak ditemukan."), { status: 404 });
  Object.assign(row, payload);
  const { password, ...safe } = row;
  return ok("Pengguna berhasil diperbarui.", safe);
}

export async function destroy(id) {
  await delay();
  const i = db.findIndex((r) => r.id === Number(id));
  if (i < 0) throw Object.assign(new Error("Pengguna tidak ditemukan."), { status: 404 });
  db.splice(i, 1);
  return ok("Pengguna berhasil dihapus.", null);
}

export async function resendAccount(id) {
  await delay();
  const row = db.find((r) => r.id === Number(id));
  if (!row) throw Object.assign(new Error("Pengguna tidak ditemukan."), { status: 404 });
  return ok("Informasi akun dikirim ulang.", {
    delivery: {
      email: { status: "sent", message: "Email aktivasi terkirim (mock).", driver: "log" },
      whatsapp: { status: "not_configured", message: "Layanan WhatsApp belum dikonfigurasi (mock)." },
    },
  });
}

export async function resetRequests() {
  await delay();
  return ok("Data permintaan reset berhasil diambil.", []);
}

export async function resolveResetRequest(id) {
  await delay();
  void id;
  return ok("Permintaan ditandai selesai.", null);
}

export { ROLE_LABEL };
