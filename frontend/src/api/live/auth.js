/**
 * live/auth.js — POST /login, POST /logout (backend Laravel + Sanctum).
 * Signature SAMA dengan mock/auth.js.
 */
import { client, setToken } from "../client.js";
import { ep } from "../endpoints.js";

const USER_KEY = "simagang_user";

export async function login({ email, password } = {}) {
  // Form login menerima email ATAU username; backend mencocokkan keduanya
  // lewat kolom `username`, jadi nilai form dipetakan ke sana.
  const res = await client.post(ep.auth.login, { username: email, password });
  // Backend: { message, data: { token, token_type, user } }
  if (res?.data?.token) {
    setToken(res.data.token);
    try {
      localStorage.setItem(USER_KEY, JSON.stringify(res.data.user ?? null));
    } catch {
      /* abaikan */
    }
  }
  return res;
}

export async function logout() {
  try {
    await client.post(ep.auth.logout);
  } catch {
    /* token mungkin sudah tidak valid — tetap bersihkan lokal */
  }
  setToken(null);
  try {
    localStorage.removeItem(USER_KEY);
  } catch {
    /* abaikan */
  }
  return { message: "Logout berhasil.", data: null };
}

export async function forgotPassword(username) {
  const res = await client.post(ep.auth.forgotPassword, { username });
  return res;
}

/** Mengatur password awal lewat tautan aktivasi (email + token). */
export async function setPassword({ email, token, password, passwordConfirmation } = {}) {
  const res = await client.post(ep.auth.setPassword, {
    email,
    token,
    password,
    password_confirmation: passwordConfirmation,
  });
  return res;
}

export function currentUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || "null");
  } catch {
    return null;
  }
}
