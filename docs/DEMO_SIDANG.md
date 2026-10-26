# SiMagang — Persiapan Demo Sidang & Deployment

Dokumen ini merangkum status keamanan, cara menjalankan aplikasi, skenario demo
per peran, serta hal yang belum dapat diverifikasi tanpa kredensial/server nyata.

> **Penting:** jangan menyalin nilai rahasia (API key, password produksi) ke
> dokumen ini atau ke repository. Semua kredensial dibaca dari `.env` server.

---

## 1. Status singkat

| Area | Status | Bukti |
|---|---|---|
| P1 #1 kuota + transaksi + lock baris perusahaan | Selesai | `QuotaAndPeriodLogicTest`, `PlacementIntegrityTest` |
| P1 #2 akses profil siswa berbasis penugasan | Selesai | `StudentProfileAccessTest` |
| P1 #3 penugasan guru & pembimbing industri | Selesai (admin + perusahaan) | `PlacementAssignmentTest`, `SupervisorScopeTest` |
| P1 #4 audit log periode PKL | Selesai | `PklPeriodAuditTest` |
| P1 #5 konsistensi otorisasi perusahaan | Selesai untuk alur lamaran/penempatan | `SecurityHardeningTest`, `CompanyApplicationReviewTest` |
| P2 dokumen, CORS, reset password, akun demo | Selesai | `P2HardeningTest` |
| AI Journal Assistant (backend proxy) | Kode + tes selesai; **runtime butuh `AI_API_KEY`** | `JournalAiAssistantTest` |
| E2E satu siklus PKL via API | Selesai | `EndToEndFlowTest` |

Hasil pengujian terakhir: **380 tes backend lulus (1.361 assertion)**.
Frontend: `npm run build` berhasil. `npm run lint` masih melaporkan error
**pre-existing** di berkas yang tidak disentuh: 51 masalah (42 error) pada
pemeriksaan terakhir, turun dari baseline 54 masalah (43 error) sebelum sesi
ini. Berkas yang diubah pada sesi ini lolos lint (lihat bagian 7).

---

## 2. Konfigurasi lingkungan

### Backend (`backend/.env`)

Wajib:

```dotenv
APP_ENV=production           # atau local untuk demo di laptop
APP_DEBUG=false              # WAJIB false di server demo/publik
APP_URL=https://domain-anda
FRONTEND_URL=https://domain-frontend-anda

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=simagang
DB_USERNAME=...
DB_PASSWORD=...

# Origin frontend yang diizinkan (pisahkan koma, TANPA wildcard)
CORS_ALLOWED_ORIGINS=https://domain-frontend-anda
```

AI Journal Assistant (opsional; tanpa key, endpoint mengembalikan 503 yang aman):

```dotenv
AI_API_KEY=...                                  # rahasi, hanya di server
AI_BASE_URL=https://api.z.ai/api/paas/v4        # OpenAI-compatible (Z.ai/GLM)
AI_MODEL=glm-5.3
AI_TIMEOUT_SECONDS=20
AI_MAX_DRAFT_CHARS=1500
AI_RATE_LIMIT_PER_MINUTE=8
```

Lain-lain: `FONNTE_TOKEN` (WhatsApp, opsional), `MAIL_*` (email aktivasi),
`ACCOUNT_ACTIVATION_EXPIRES_HOURS`, `PASSWORD_RESET_EXPIRES_HOURS`,
`SEED_DEMO_ACCOUNTS` (biarkan `false` di production).

### Frontend (`frontend/.env`)

```dotenv
VITE_USE_MOCK=false
VITE_API_URL=http://localhost:8000/api     # ganti dengan URL backend saat deploy
```

---

## 3. Menjalankan aplikasi

### Lokal (Windows, sudah terpasang PHP/Composer/Node/MySQL)

1. `cd backend && composer install`
2. Siapkan database MySQL baru, isi `backend/.env`.
3. `php artisan key:generate` (sekali saja per instalasi).
4. `php artisan migrate --seed` — **hanya untuk database lokal/demo baru.**
   Jangan menjalankan migrasi ke database produksi tanpa backup.
5. `php artisan storage:link` agar dokumen yang diunggah dapat diunduh.
6. `php artisan serve` (http://localhost:8000)
7. `cd frontend && npm install && npm run dev` (http://localhost:5173)
8. Alternatif cepat Windows: klik dua kali `start-dev.bat`.

### AI Assistant

1. Buat API key di penyedia OpenAI-compatible (default Z.ai — lihat
   `https://docs.z.ai/guides/develop/openai/python`).
2. Isi `AI_API_KEY` di `backend/.env`, lalu `php artisan config:clear`.
3. Uji dari terminal (tanpa mencetak key):

   ```bash
   curl -sS -o /dev/null -w "%{http_code}\n" \
     -H "Authorization: Bearer $AI_API_KEY" \
     -H "Content-Type: application/json" \
     -d "{\"model\":\"$AI_MODEL\",\"messages\":[{\"role\":\"user\",\"content\":\"ping\"}]}" \
     "$AI_BASE_URL/chat/completions"
   ```

4. Di aplikasi: login sebagai siswa → menu **AI Journal Assistant** →
   tulis draf → **Analisis dengan AI**. Jika key belum diisi, UI menampilkan
   pesan bahwa fitur belum dikonfigurasi (bukan error palsu).

> Status saat ini: kode dan tes otomatis sudah selesai, tetapi pemanggilan model
> nyata **belum diuji** karena `AI_API_KEY` belum tersedia di lingkungan kerja.

---

## 4. Akun demo (dari `TestAccountsSeeder`)

Seeder berjalan di lingkungan non-production, atau di production hanya bila
`SEED_DEMO_ACCOUNTS=true` (jangan dipakai untuk server publik jangka panjang).

| Peran | Username | Email | Password awal |
|---|---|---|---|
| Admin Sekolah | `admin` | `admin@smk.sch.id` | `admin123` |
| Guru Pembimbing | `guru` | `guru@smk.sch.id` | `guru123` |
| Siswa | `siswa` | `siswa@smk.sch.id` | `siswa123` |
| Perusahaan | `industri` | `industri@smk.sch.id` | `industri123` |
| Pembimbing Industri | `pembimbing` | `pembimbing@smk.sch.id` | `pembimbing123` |

Ganti seluruh password di atas sebelum server dapat diakses publik. Seeder juga
membuat satu periode aktif, satu perusahaan mitra, dan satu penempatan siswa
dengan guru + pembimbing industri yang sudah ditugaskan.

---

## 5. Skenario demo sidang (±10 menit)

1. **Siswa — pengajuan**
   Login `siswa` → menu Pengajuan → pilih perusahaan mitra → kirim.
2. **Perusahaan — review**
   Login `industri` → Pengajuan Magang → terima lamaran siswa.
3. **Siswa — pilihan final**
   Login `siswa` → Pilih Perusahaan → pilih tawaran yang diterima.
   Sistem otomatis membuat **penempatan resmi** (tanpa persetujuan admin).
4. **Admin — penugasan guru**
   Login `admin` → Data Siswa → Detail siswa → bagian
   **Penugasan Guru Pembimbing** → pilih guru → Simpan. Pesan sukses/validasi
   tampil di halaman yang sama.
5. **Perusahaan — penugasan pembimbing industri**
   Login `industri` → Pengajuan Magang → tombol **Tugaskan Pembimbing** →
   pilih pembimbing industri → Tugaskan. Endpoint khusus ini hanya menulis
   `supervisor_id` dan mencatat audit log.
6. **Siswa — jurnal dengan AI**
   Login `siswa` → AI Journal Assistant → tulis draf singkat → Analisis dengan AI
   → jawab pertanyaan → Buat Revisi → Kirim Jurnal.
   Jelaskan bahwa AI hanya menyarankan; **verifikasi tetap oleh pembimbing**.
7. **Pembimbing industri — verifikasi**
   Login `pembimbing` → Verifikasi Jurnal → setujui / minta revisi.
   Tunjukkan pembimbing hanya melihat siswa yang ditugaskan kepadanya.
8. **Guru & pembimbing — penilaian**
   Guru mengisi komponen guru (30%), pembimbing mengisi komponen industri (70%).
9. **Admin — publikasi nilai**
   Tandai PKL selesai → hitung nilai akhir → publikasikan.
   Login siswa → lihat nilai yang sudah dipublikasikan.

### Verifikasi manual yang belum dijalankan otomatis

- Interaksi UI nyata (klik tombol, tampilan pesan sukses/gagal) di browser.
- Unggah dokumen melalui form dan unduh dari UI.
- Absensi dengan kamera/geolokasi (butuh izin browser & HTTPS).
- Pengiriman email/WhatsApp nyata (butuh kredensial SMTP/Fonnte).

Semua alur API di atas (kecuali unggah/absen berbasis perangkat) sudah diuji
otomatis melalui `EndToEndFlowTest`.

---

## 6. Checklist deployment

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` terisi.
- [ ] HTTPS aktif (wajib untuk kamera/geolokasi dan token Bearer).
- [ ] `CORS_ALLOWED_ORIGINS` hanya berisi domain frontend resmi.
- [ ] Database produksi terpisah + **backup otomatis** (mis. `mysqldump` harian).
- [ ] `php artisan storage:link`; direktori `storage/` dan `bootstrap/cache/`
      dapat ditulis proses PHP.
- [ ] `php artisan config:cache route:cache view:cache` setelah `.env` final.
- [ ] `SEED_DEMO_ACCOUNTS=false`; hapus/ganti akun demo.
- [ ] Rate limit aktif (`login`, `forgot-password`, `ai-journal`).
- [ ] Monitor kuota/biaya AI di dashboard penyedia; endpoint dibatasi 8/menit/siswa
      dan input maksimal 1.500 karakter.
- [ ] Email aktivasi & WhatsApp (opsional) diuji dengan kredensial nyata.
- [ ] Scheduler/cron untuk tugas periodik (jika digunakan) sudah terpasang.
- [ ] Uji ulang `php artisan test` pada salinan database uji (SQLite in-memory).

---

## 7. Risiko & batasan yang belum terselesaikan

1. **AI runtime belum diuji dengan penyedia nyata** — `AI_API_KEY` belum ada.
   Semua jalur sukses/gagal diuji dengan HTTP fake.
2. **Akses pra-penempatan pembimbing industri** — role `supervisor` masih dapat
   membaca daftar lamaran/interview pada perusahaannya (warisan tautan
   `company_supervisors`), meskipun data pasca-penempatan (placement, jurnal,
   absensi, penilaian, profil siswa, daftar pengguna) sudah dibatasi per
   penugasan. Bila kebijakan sekolah mengharuskan pembimbing industri hanya
   melihat siswa yang ditugaskan sejak tahap lamaran, controller lamaran/
   interview perlu dipersempit pada iterasi berikutnya.
3. **`npm run lint` belum bersih** — 42 error pre-existing di berkas lain
   (contoh: `react-hooks/set-state-in-effect`, impor tak terpakai `progressMock`/
   `progressLive` di `src/api/index.js`). Berkas yang diubah pada pekerjaan ini
   lolos lint dan jumlah error tidak bertambah.
4. **Tidak ada suite tes frontend** di `package.json`; verifikasi frontend
   dilakukan lewat build + lint + tinjauan manual.
5. **Seeder akun demo** tetap berisi password yang diketahui publik; aman hanya
   untuk demo lokal, tidak untuk produksi jangka panjang.
