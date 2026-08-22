<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotController extends Controller
{
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
            'history' => 'nullable|array',
        ]);

        $userMessage = $request->input('message');
        $history = $request->input('history', []); // Format: [['role' => 'user'|'model', 'text' => '...']]

        try {
            // 1. Ambil Pengetahuan Statis dari llms-full.txt
            $filePath = public_path('llms-full.txt');
            $knowledgeBase = file_exists($filePath) ? file_get_contents($filePath) : 'Informasi jasa sewa photobooth premium Malang.';

            // 2. Ambil Jadwal Dinamis dari Database
            $schedules = Schedule::where('end_date', '>=', now())
                ->orderBy('start_date')
                ->get()
                ->map(function ($s) {
                    $status = ($s->used >= $s->capacity) ? 'PENUH (Fully Booked)' : 'TERSEDIA TERBATAS';
                    return "- Tanggal {$s->start_date->format('d M Y')} s/d {$s->end_date->format('d M Y')}: {$s->title} ({$s->used}/{$s->capacity} slot terpakai) -> Status: {$status}";
                })
                ->implode("\n");

            // 3. Susun System Instruction & Batasan Ketat
            $systemInstruction = "
Anda adalah 'Photomate Assistant', asisten AI resmi dari Photomate.id yang ramah, profesional, dan solutif.
Tugas Anda adalah melayani tanya jawab calon pelanggan seputar sewa photobooth, harga, paket, rekomendasi, dan jadwal.

BASIS PENGETAHUAN LAYANAN & PRICING:
---
{$knowledgeBase}
---

STATUS JADWAL DI DATABASE (REAL-TIME):
Berikut adalah jadwal tanggal yang sudah terisi event/booking saat ini. Jika tanggal tidak ada di daftar bawah ini, berarti slot pada tanggal tersebut MASIH TERSEDIA (Kapasitas maksimal 2 device per hari):
---
{$schedules}
---

BATASAN KETAT (GUARDRAILS):
1. Anda HANYA diperbolehkan menjawab pertanyaan seputar Photomate.id, photobooth, paket sewa, harga, dan jadwal ketersediaan.
2. Jika pengguna menanyakan di luar topik di atas (misal: coding, matematika, resep makanan, gosip, atau merek kompetitor), Anda WAJIB menolak dengan sopan dan mengarahkan mereka kembali ke layanan Photomate.
3. Gunakan Bahasa Indonesia yang ramah, santun, dan gunakan emoji sesekali agar menarik (misal: 😊, 📸, 📅).
4. Jika pengguna berminat memesan atau memerlukan bantuan manusia, berikan link WhatsApp admin: https://wa.me/6287787405280
5. JANGAN PERNAH menggunakan format markdown tebal (**teks**) atau miring (*teks*) dalam respon Anda. Kirimkan semua jawaban dalam bentuk teks biasa (plain text) tanpa simbol bintang agar nyaman dibaca di chatbox.
            ";

            // 4. Susun Payload isi Percakapan (Contents)
            // Memasukkan riwayat obrolan agar AI ingat konteks sebelumnya
            $contents = [];
            foreach ($history as $chat) {
                $contents[] = [
                    'role' => $chat['role'] === 'model' ? 'model' : 'user',
                    'parts' => [['text' => $chat['text']]]
                ];
            }
            // Tambahkan pesan baru dari user di paling akhir
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $userMessage]]
            ];

            // 5. Kirim HTTP Request ke Gemini API
            $apiKey = env('GEMINI_API_KEY');
            if (!$apiKey) {
                return response()->json(['error' => 'API Key belum dikonfigurasi.'], 500);
            }

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key={$apiKey}", [
                        'contents' => $contents,
                        'systemInstruction' => [
                            'parts' => [['text' => $systemInstruction]]
                        ],
                        'generationConfig' => [
                            'temperature' => 0.2, // Rendah agar konsisten & patuh
                            'maxOutputTokens' => 600,
                        ]
                    ]);

            if ($response->failed()) {
                Log::error('Gemini API Error: ' . $response->body());
                return response()->json(['error' => 'Gagal memproses pesan AI.'], 500);
            }

            $data = $response->json();
            $aiResponseText = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Maaf, saya sedang mengalami kendala teknis.';

            return response()->json([
                'success' => true,
                'message' => $aiResponseText
            ]);

        } catch (\Exception $e) {
            Log::error('Chatbot error: ' . $e->getMessage());
            return response()->json(['error' => 'Terjadi kesalahan sistem.'], 500);
        }
    }
}
