/**
 * mock/auth.js — POST /login, POST /logout
 * Kolom user backend: id, name, username, email, role, is_active.
 */
import { delay, ok } from "./_shared.js";

const USERS = [
  { id: 1, name: "Admin TU", username: "admin.tu", email: "tu@smkn1.sch.id", role: "admin", is_active: true },
  { id: 2, name: "Hartono, S.Kom", username: "hartono", email: "hartono@smkn1.sch.id", role: "teacher", is_active: true },
  { id: 3, name: "Rina Amelia", username: "rina.amelia", email: "rina@smkn1.sch.id", role: "student", is_active: true },
  { id: 4, name: "PT Maju Jaya Abadi", username: "majujaya", email: "hrd@majujaya.id", role: "company", is_active: true },
];

export async function login({ email, password } = {}) {
  await delay();
  const user = USERS.find((u) => u.email === email || u.username === email);
  if (!user || !password) {
    const err = new Error("Email atau kata sandi salah.");
    err.status = 401;
    throw err;
  }
  try {
    localStorage.setItem("simagang_token", `mock-token-${user.id}`);
    localStorage.setItem("simagang_user", JSON.stringify(user));
  } catch {
    /* abaikan */
  }
  return ok("Login berhasil.", {
    token: `mock-token-${user.id}`,
    token_type: "Bearer",
    user,
  });
}

export async function logout() {
  await delay(120);
  try {
    localStorage.removeItem("simagang_token");
    localStorage.removeItem("simagang_user");
  } catch {
    /* abaikan */
  }
  return ok("Logout berhasil.", null);
}

export async function forgotPassword(username) {
  await delay();
  void username;
  return { message: "Permintaan terkirim. Hubungi admin sekolah untuk mendapatkan password baru.", data: null };
}

export async function setPassword({ email, token, password } = {}) {
  await delay();
  if (!email || !token || !password) {
    const err = new Error("Tautan aktivasi tidak valid atau sudah kadaluarsa.");
    err.status = 422;
    throw err;
  }
  return ok("Password berhasil diatur. Silakan masuk.", null);
}

export async function changePassword(current_password, password) {
  await delay();
  void current_password; void password;
  return ok("Password berhasil diganti.", null);
}

export function currentUser() {
  try {
    return JSON.parse(localStorage.getItem("simagang_user") || "null");
  } catch {
    return null;
  }
}
