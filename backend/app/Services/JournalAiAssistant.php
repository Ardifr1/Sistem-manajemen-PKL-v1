<?php

namespace App\Services;

use App\Exceptions\JournalAiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * AI Journal Assistant — memeriksa draf jurnal harian siswa.
 *
 * Integrasi lewat backend (OpenAI-compatible Chat Completions). API key hanya
 * dibaca dari konfigurasi server; frontend tidak pernah menerima kunci.
 *
 * Batasan yang disengaja:
 * - hanya mengirim teks draf jurnal (tanpa identitas siswa/perusahaan),
 * - keluaran wajib JSON terstruktur dan divalidasi ulang di server,
 * - AI TIDAK mengubah status jurnal; verifikasi tetap oleh pembimbing industri.
 */
class JournalAiAssistant
{
    public function isConfigured(): bool
    {
        return filled(config('simagang.ai.api_key'));
    }

    /**
     * @return array{summary: string, missing: array<int, string>, questions: array<int, array{id: string, question: string, hint: string, options: array<int, string>}>, revised_activity: string, model: string}
     *
     * @throws JournalAiException
     */
    public function review(string $draft): array
    {
        if (! $this->isConfigured()) {
            throw JournalAiException::notConfigured();
        }

        $baseUrl = rtrim((string) config('simagang.ai.base_url'), '/');

        try {
            $response = Http::withToken((string) config('simagang.ai.api_key'))
                ->acceptJson()
                ->timeout((int) config('simagang.ai.timeout_seconds', 20))
                ->post($baseUrl.'/chat/completions', [
                    'model' => (string) config('simagang.ai.model'),
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => "Draf jurnal harian siswa:\n\n".trim($draft)],
                    ],
                ]);
        } catch (ConnectionException) {
            throw JournalAiException::unavailable();
        }

        if ($response->status() === 429) {
            throw JournalAiException::quotaExceeded();
        }

        if ($response->failed()) {
            throw JournalAiException::unavailable();
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw JournalAiException::invalidResponse();
        }

        return $this->normalize($this->decode($content), $draft);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah asisten penulisan jurnal PKL untuk siswa SMK di Indonesia.

Tugasmu HANYA memeriksa dan menyempurnakan draf jurnal harian yang diberikan.
Aturan wajib:
1. Jangan mengarang kegiatan, alat, hasil, angka, nama orang, atau perusahaan.
2. Pertahankan seluruh fakta pada draf; hanya perjelas struktur, ejaan, dan kelengkapan.
3. Jangan menilai, memberi nilai, atau menyetujui jurnal.
4. Jangan meminta data pribadi (NIK, alamat, nomor telepon, kata sandi).
5. Gunakan bahasa Indonesia baku yang sederhana.
6. Balas HANYA dengan JSON valid (tanpa markdown) dengan skema:
{
  "summary": "ringkasan singkat isi draf, maksimal 240 karakter",
  "missing": ["informasi penting yang belum jelas, maksimal 5 item"],
  "questions": [
    {
      "id": "kata-kunci-singkat",
      "question": "pertanyaan klarifikasi",
      "hint": "petunjuk singkat",
      "options": ["pilihan jawaban, maksimal 6"]
    }
  ],
  "revised_activity": "versi jurnal yang lebih jelas dan lengkap, maksimal 900 karakter"
}
Buat maksimal 3 pertanyaan. Jika draf sudah lengkap, `missing` dan `questions` boleh berisi array kosong.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JournalAiException
     */
    private function decode(string $content): array
    {
        $trimmed = trim($content);

        // Sebagian model membungkus JSON dengan code fence.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            throw JournalAiException::invalidResponse();
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{summary: string, missing: array<int, string>, questions: array<int, array{id: string, question: string, hint: string, options: array<int, string>}>, revised_activity: string, model: string}
     *
     * @throws JournalAiException
     */
    private function normalize(array $payload, string $draft): array
    {
        $revised = $this->cleanString($payload['revised_activity'] ?? '', 900);

        if ($revised === '') {
            throw JournalAiException::invalidResponse();
        }

        $summary = $this->cleanString($payload['summary'] ?? '', 240);

        if ($summary === '') {
            $summary = Str::limit(trim($draft), 200);
        }

        $missing = [];

        foreach ((array) ($payload['missing'] ?? []) as $item) {
            if (! is_string($item)) {
                continue;
            }

            $clean = $this->cleanString($item, 160);

            if ($clean !== '') {
                $missing[] = $clean;
            }

            if (count($missing) >= 5) {
                break;
            }
        }

        $questions = [];

        foreach ((array) ($payload['questions'] ?? []) as $index => $question) {
            if (! is_array($question) || count($questions) >= 3) {
                continue;
            }

            $text = $this->cleanString($question['question'] ?? '', 200);

            if ($text === '') {
                continue;
            }

            $options = [];

            foreach ((array) ($question['options'] ?? []) as $option) {
                if (! is_string($option)) {
                    continue;
                }

                $cleanOption = $this->cleanString($option, 80);

                if ($cleanOption !== '') {
                    $options[] = $cleanOption;
                }

                if (count($options) >= 6) {
                    break;
                }
            }

            $id = $this->cleanString($question['id'] ?? '', 40);

            $questions[] = [
                'id' => $id !== '' ? Str::slug($id) : 'q'.($index + 1),
                'question' => $text,
                'hint' => $this->cleanString($question['hint'] ?? '', 120),
                'options' => array_values(array_unique($options)),
            ];
        }

        return [
            'summary' => $summary,
            'missing' => $missing,
            'questions' => $questions,
            'revised_activity' => $revised,
            'model' => (string) config('simagang.ai.model'),
        ];
    }

    private function cleanString(mixed $value, int $maxLength): string
    {
        if (! is_string($value)) {
            return '';
        }

        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');

        return Str::limit($clean, $maxLength, '');
    }
}
