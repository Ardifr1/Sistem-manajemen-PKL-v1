# AGENTS.md — SiMagang

## Proyek

SiMagang — Sistem informasi magang untuk sekolah: pengajuan, penempatan, jurnal harian dengan AI Assistant, monitoring, dan penilaian akhir.

**Stack:** Laravel 13 + Sanctum (API backend), React 19 + Vite (frontend), MySQL.
**Role:** Admin Sekolah, Siswa, Guru Pembimbing, Perusahaan, Pembimbing Industri.
**Auth:** Bearer token (Sanctum), login via email atau username, token di localStorage `simagang_token`.
**Format respons API yang umum:** `{ message, data }`. Beberapa respons error (401/403/422) mungkin memiliki struktur berbeda.

## Referensi tugas

Sebelum mengerjakan tugas, baca file yang relevan untuk area tersebut:

- **API Laravel:** `backend/routes/api.php` (semua endpoint + middleware), `backend/app/Http/Controllers/` (logika per role).
- **API frontend:** `frontend/src/api/index.js` (flag `USE_MOCK`), `frontend/src/api/client.js` (fetch wrapper untuk backend live).
- **Validasi & aturan:** `backend/tests/` (tests/Unit untuk unit testing, tests/Feature untuk integrasi).
- **Struktur data:** `backend/app/Models/` (berhubungan dengan izin dan peran) untuk memahami kontrak.
- **Login & akun:** `README.md` untuk deskripsi akun test, alamat login, proses instalasi.

**AI membaca hanya file yang relevan untuk tugas saat ini**, tidak membaca seluruh proyek. Gunakan kembali hasil pemeriksaan yang masih valid.

## Prinsip SiMagang

1. **Jangan ubah di luar ruang lingkup tugas.** Setiap perubahan harus memiliki alasan teknis yang jelas dan risiko yang dijelaskan.
2. **Jaga desain aplikasi.** Jangan ubah route, model, skema database, atau kontrak API kecuali tugas meminta.
3. **Keamanan tetap.** Middleware `auth:sanctum`, `account.active`, dan `password.changed` tidak boleh dikurangi.
4. **Verifikasi wajib.** Setiap perubahan diverifikasi dengan tes yang relevan sebelum selesai.

## Lapisan otorisasi

**Route middleware** (diterapkan di `routes/api.php`):
- **Publik** (`/login`, `/forgot-password`, `/set-password`): hanya `throttle` (pembatasan laju)
- **Dilindungi** (semua route di bawah `Route::middleware(['auth:sanctum', 'account.active', 'password.changed'])`):
  - `auth:sanctum` (Sanctum token)
  - `account.active` (kolom `is_active` di DB)
  - `password.changed` (field `must_change_password`)
  - **Penambahan opsional per route:**
    - `/users/*` dan `/password-reset-requests/*`: middleware `role:admin,company,supervisor,teacher` (HANYA untuk user management)
    - Route spesifik lainnya tidak menggunakan middleware role ini

**Authorization policy** (di `app/Policies/`, dipanggil via `Gate::authorize()` di controller):
- Setiap controller memanggil `Gate::authorize()` untuk otorisasi spesifik
- Contoh: `Gate::authorize('viewAny', User::class)` di `UserController`
- Contoh: `Gate::authorize('viewAny', Journal::class)` di `JournalController`
- Contoh: `Gate::forUser($user)->check('view', $placement)` di `JournalController`

Route middleware dan authorization policy adalah dua lapis yang terpisah. **Jangan mengasumsikan seluruh endpoint memiliki susunan middleware identik.** Verifikasi middleware per route sebelum mengklaim otorisasi.

## Mock vs Live (frontend)

Flag `USE_MOCK` di `frontend/src/api/index.js` mengontrol sumber data backend. Ketika `USE_MOCK=false`, frontend menggunakan `client.js` untuk berkomunikasi dengan backend Laravel di `VITE_API_URL` (default `http://localhost:8000/api`). Perubahan antara mock dan live **tidak mengubah signature fungsi API**.

## Prioritas proyek

**Seluruh temuan P1 harus diselesaikan dan diverifikasi dengan tes sebelum pekerjaan P2 atau integrasi AI Journal Assistant dimulai.**

## Perintah pengujian yang diverifikasi

| Perintah | Lokasi | Sumber konfigurasi |
|---|---|---|
| `cd backend && php artisan test` | `backend/` | `composer.json` script `test` (`@php artisan test`), `phpunit.xml` (SQLite in-memory, testsuites `Unit` + `Feature`) |
| `cd frontend && npm run lint` | `frontend/` | `package.json` script `lint` (`eslint .`) |

Tidak ada command tes frontend yang tersedia (`test` tidak ada di `frontend/package.json`). Tidak ada perintah testing lain yang ditambahkan karena belum diverifikasi dari konfigurasi yang tersedia.

## File jangan diubah

- `backend/AGENTS.md` — biarkan apa adanya.
- `.env` / `.env.example` — jangan simpan atau tampilkan kredensial.
- `README.md` — jangan mengubah tanpa permintaan eksplisit.