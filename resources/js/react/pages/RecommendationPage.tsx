import React, { useState, useRef } from "react";
import { Link } from "react-router-dom";
import { motion, AnimatePresence } from "framer-motion";
import Navbar from "../components/Navbar";
import Footer from "../components/Footer";
import {
  calculatePackageRecommendation,
  RecommendationInput,
  RecommendationResult,
  PackageOption,
} from "../utils/photoboothRecommendation";

const WA_NUMBER = "6287787405280";

const formatRupiah = (num: number) => {
  return "Rp " + num.toLocaleString("id-ID");
};

function WhatsAppIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      fill="currentColor"
      viewBox="0 0 24 24"
      aria-hidden
    >
      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
    </svg>
  );
}

export default function RecommendationPage() {
  // Form States
  const [days, setDays] = useState<number | "">(1);
  const [guests, setGuests] = useState<number | "">("");
  const [peoplePerSession, setPeoplePerSession] = useState<number | "">(3);

  // Error States
  const [errors, setErrors] = useState<{
    days?: string;
    guests?: string;
    peoplePerSession?: string;
  }>({});

  // Calculation Result
  const [result, setResult] = useState<RecommendationResult | null>(null);
  const [searchedInput, setSearchedInput] = useState<RecommendationInput | null>(null);
  const [searchedGuests, setSearchedGuests] = useState<number | "">("");

  // Ref to scroll to results
  const resultRef = useRef<HTMLDivElement>(null);

  const validate = (): boolean => {
    const tempErrors: typeof errors = {};
    let isValid = true;

    if (days === "" || days <= 0) {
      tempErrors.days = "Jumlah hari minimal 1 hari.";
      isValid = false;
    }
    if (guests === "" || guests <= 0) {
      tempErrors.guests = "Total audience minimal 1 orang.";
      isValid = false;
    }
    if (peoplePerSession === "" || peoplePerSession <= 0) {
      tempErrors.peoplePerSession = "Rata-rata orang per sesi minimal 1 orang.";
      isValid = false;
    }

    setErrors(tempErrors);
    return isValid;
  };

  const handleCalculate = (e: React.FormEvent) => {
    e.preventDefault();
    if (!validate()) return;

    const calculatedGuestsPerDay = Number(guests) / Number(days);

    const inputData: RecommendationInput = {
      days: Number(days),
      guestsPerDay: calculatedGuestsPerDay,
      peoplePerSession: Number(peoplePerSession),
      sessionDuration: 4, // Hardcoded to 4 minutes
    };

    const res = calculatePackageRecommendation(inputData);
    setResult(res);
    setSearchedInput(inputData);
    setSearchedGuests(Number(guests));

    // Scroll to results after a short delay for animation
    setTimeout(() => {
      resultRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    }, 100);
  };

  // Helper to build WA booking message
  const getWAUrl = (rec: PackageOption | null, isCustom = false) => {
    if (!searchedInput) return `https://wa.me/${WA_NUMBER}`;

    let message = "";
    if (isCustom || !rec) {
      message = `Hai Photomate 👋 Saya ingin konsultasi paket custom photobooth.
      
Detail Event:
- Jumlah Hari: ${searchedInput.days} Hari
- Total Audience: ${searchedGuests} orang (±${Math.ceil(searchedInput.guestsPerDay)} orang/hari)
- Rata-rata Orang/Sesi: ${searchedInput.peoplePerSession} orang
- Durasi/Sesi: ${searchedInput.sessionDuration} menit

Mohon rekomendasikan konfigurasi terbaik untuk acara kami. Terima kasih!`;
    } else {
      message = `Hai Photomate 👋 Saya tertarik untuk memesan paket photobooth hasil rekomendasi website.

Detail Paket Rekomendasi:
- Paket: ${rec.devices} Device × ${rec.hours} Jam / Hari
- Durasi Event: ${searchedInput.days} Hari
- Total Operasional: ${rec.devices * rec.hours * searchedInput.days} jam perangkat

Detail Karakteristik Event:
- Total Audience: ${searchedGuests} orang (±${Math.ceil(searchedInput.guestsPerDay)} orang/hari)
- Rata-rata Orang/Sesi: ${searchedInput.peoplePerSession} orang
- Durasi/Sesi: ${searchedInput.sessionDuration} menit

Mohon info ketersediaan slot tanggal dan penawaran terbaiknya. Terima kasih!`;
    }

    return `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent(message)}`;
  };

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col justify-between">
      <Navbar />

      <main className="container mx-auto px-4 pt-28 pb-20 max-w-6xl flex-grow">
        {/* Breadcrumb */}
        <nav className="mb-8" aria-label="Breadcrumb">
          <Link
            to="/"
            className="inline-flex items-center gap-2 text-gray-600 hover:text-primary font-medium transition-colors"
          >
            <svg
              className="w-5 h-5 shrink-0"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M15 19l-7-7 7-7"
              />
            </svg>
            Beranda
          </Link>
        </nav>

        {/* Header */}
        <header className="mb-12 text-center md:text-left">
          <h1 className="text-3xl md:text-5xl font-extrabold text-primary tracking-tight leading-tight">
            Berapa Jam Photobooth yang Kamu Butuhkan?
          </h1>
          <span className="block w-24 h-1.5 bg-primary mt-6 rounded-full mx-auto md:mx-0" />
          <p className="text-gray-600 mt-5 max-w-3xl text-base md:text-lg leading-relaxed">
            Hitung kebutuhan photobooth berdasarkan jumlah tamu dan karakteristik event kamu.
            Photomate akan membantu merekomendasikan durasi serta jumlah device yang paling sesuai.
          </p>
        </header>

        {/* Form Section */}
        <section className="bg-white rounded-2xl border border-gray-100 shadow-xl p-6 md:p-10 mb-12">
          <form onSubmit={handleCalculate} className="space-y-6">
            <div className="grid md:grid-cols-3 gap-6 md:gap-8">
              {/* Jumlah Hari */}
              <div className="flex flex-col">
                <label
                  htmlFor="days"
                  className="text-sm font-bold text-gray-700 mb-2"
                >
                  Jumlah Hari Event
                </label>
                <div className="relative">
                  <input
                    id="days"
                    type="number"
                    inputMode="numeric"
                    min="1"
                    value={days}
                    onChange={(e) => {
                      const val = e.target.value;
                      setDays(val === "" ? "" : Math.max(1, parseInt(val) || 1));
                    }}
                    className={`w-full px-4 py-3 rounded-xl border ${
                      errors.days ? "border-red-500" : "border-gray-300"
                    } focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-gray-900 bg-gray-50/50`}
                  />
                  <span className="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium pointer-events-none">
                    Hari
                  </span>
                </div>
                {errors.days && (
                  <p className="text-red-500 text-xs mt-1.5">{errors.days}</p>
                )}
              </div>

              {/* Jumlah Tamu per Hari */}
              <div className="flex flex-col">
                <label
                  htmlFor="guests"
                  className="text-sm font-bold text-gray-700 mb-2"
                >
                  Total Audience
                </label>
                <div className="relative">
                  <input
                    id="guests"
                    type="number"
                    inputMode="numeric"
                    min="1"
                    placeholder="Contoh: 150"
                    value={guests}
                    onChange={(e) => {
                      const val = e.target.value;
                      setGuests(val === "" ? "" : Math.max(1, parseInt(val) || 1));
                    }}
                    className={`w-full px-4 py-3 rounded-xl border ${
                      errors.guests ? "border-red-500" : "border-gray-300"
                    } focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-gray-900 bg-gray-50/50`}
                  />
                  <span className="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium pointer-events-none">
                    Orang
                  </span>
                </div>
                {errors.guests && (
                  <p className="text-red-500 text-xs mt-1.5">{errors.guests}</p>
                )}
              </div>

              {/* Rata-rata Orang per Sesi */}
              <div className="flex flex-col">
                <label
                  htmlFor="peoplePerSession"
                  className="text-sm font-bold text-gray-700 mb-2"
                >
                  Rata-rata Orang per Sesi
                </label>
                <div className="relative">
                  <input
                    id="peoplePerSession"
                    type="number"
                    inputMode="numeric"
                    min="1"
                    value={peoplePerSession}
                    onChange={(e) => {
                      const val = e.target.value;
                      setPeoplePerSession(
                        val === "" ? "" : Math.max(1, parseInt(val) || 1)
                      );
                    }}
                    className={`w-full px-4 py-3 rounded-xl border ${
                      errors.peoplePerSession ? "border-red-500" : "border-gray-300"
                    } focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-gray-900 bg-gray-50/50`}
                  />
                  <span className="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium pointer-events-none">
                    Orang
                  </span>
                </div>
                <p className="text-gray-400 text-xs mt-1.5">
                  Biasanya 2–5 orang dalam satu sesi foto.
                </p>
                {errors.peoplePerSession && (
                  <p className="text-red-500 text-xs mt-1.5">
                    {errors.peoplePerSession}
                  </p>
                )}
              </div>
            </div>

            <div className="pt-4 flex justify-center md:justify-start">
              <button
                type="submit"
                className="w-full md:w-auto px-10 py-4 rounded-full bg-primary hover:bg-primary-light text-white font-bold transition-all shadow-lg hover:shadow-xl active:scale-98 cursor-pointer flex items-center justify-center gap-3"
              >
                <svg
                  className="w-5 h-5"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2.5}
                    d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                  />
                </svg>
                Hitung Rekomendasi
              </button>
            </div>
          </form>
        </section>

        {/* Results Ref Hook */}
        <div ref={resultRef} />

        {/* Result Area */}
        <AnimatePresence>
          {result && searchedInput && (
            <motion.div
              initial={{ opacity: 0, y: 30 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -30 }}
              transition={{ duration: 0.5, ease: "easeOut" }}
              className="space-y-12"
            >
              {/* Scenario 1: Needs Custom Recommendation (No regular packages fit) */}
              {!result.recommended ? (
                <div className="bg-white rounded-2xl border border-amber-200 shadow-xl overflow-hidden">
                  <div className="bg-amber-500 px-6 py-4 flex items-center gap-3 text-white">
                    <svg
                      className="w-6 h-6 shrink-0"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={2}
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                      />
                    </svg>
                    <h3 className="font-bold text-lg">Event Kamu Membutuhkan Penanganan Khusus</h3>
                  </div>
                  <div className="p-6 md:p-10 space-y-6">
                    <p className="text-gray-700 leading-relaxed text-base md:text-lg">
                      Paketnya belum cukup buat acaramu nih. Yuk, konsultasi! Kita cari yang paling pas 🤝
                    </p>
                    <p className="text-gray-600 leading-relaxed">
                      Silakan konsultasikan kebutuhan event kamu dengan tim Photomate agar kami dapat memberikan konfigurasi yang paling sesuai.
                    </p>
                    <div className="pt-2">
                      <a
                        href={getWAUrl(null, true)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-3 px-8 py-4 rounded-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold transition shadow-lg hover:shadow-xl active:scale-98"
                      >
                        <WhatsAppIcon className="w-6 h-6" />
                        Konsultasi dengan Photomate
                      </a>
                    </div>
                  </div>
                </div>
              ) : (
                /* Scenario 2: Regular Packages Fit */
                <div className="space-y-12">
                  <div className="grid md:grid-cols-12 gap-8 items-start">
                    {/* Main Recommendation */}
                    <div className="md:col-span-7 bg-white rounded-2xl border border-gray-100 shadow-xl overflow-hidden">
                      <div className="bg-primary px-6 py-4 flex items-center justify-between text-white">
                        <span className="text-xs font-bold uppercase tracking-wider bg-white/20 px-3 py-1 rounded-md">
                          Rekomendasi Utama
                        </span>
                        <span className="text-xs font-bold px-2.5 py-1 bg-emerald-500 text-white rounded-full">
                          Sangat Direkomendasikan
                        </span>
                      </div>
                      <div className="p-6 md:p-8 space-y-6">
                        <div className="space-y-1">
                          <p className="text-xs text-gray-500 font-semibold uppercase tracking-wider">Per Hari</p>
                          <h2 className="text-3xl md:text-4xl font-extrabold text-primary">
                            {result.recommended.devices} Device × {result.recommended.hours} Jam
                          </h2>
                          <p className="text-sm text-gray-600 font-medium mt-1">
                            Untuk: <strong className="text-primary">{searchedInput.days} Hari Event</strong>
                          </p>
                        </div>

                        {/* Explanatory text */}
                        <div className="bg-blue-50/50 border border-blue-100/50 rounded-xl p-4 text-gray-700 text-sm md:text-base leading-relaxed">
                          {result.recommended.devices === 2 ? (
                            "Dengan dua device, antrean dapat dibagi ke dua photobooth sehingga jumlah sesi yang dibutuhkan dapat diselesaikan dalam waktu yang lebih singkat."
                          ) : (
                            "Sangat efisien untuk event dengan skala tamu sedang, menggunakan satu unit device photobooth secara optimal."
                          )}
                        </div>

                        {/* Detailed Metrics */}
                        <div className="border-t border-gray-100 pt-6">
                          <h4 className="text-xs font-bold text-gray-500 uppercase tracking-wider mb-4">Detail Kalkulasi Kebutuhan</h4>
                          <ul className="grid grid-cols-2 gap-y-4 gap-x-6 text-sm text-gray-700">
                            <li className="flex flex-col">
                              <span className="text-gray-400 text-xs">Total Audience Event</span>
                              <strong className="text-gray-900 mt-0.5">{searchedGuests} orang</strong>
                            </li>
                            <li className="flex flex-col">
                              <span className="text-gray-400 text-xs">Estimasi Audience / Hari</span>
                              <strong className="text-gray-900 mt-0.5">±{Math.ceil(searchedInput.guestsPerDay)} orang</strong>
                            </li>
                            <li className="flex flex-col">
                              <span className="text-gray-400 text-xs">Estimasi Sesi / Hari</span>
                              <strong className="text-gray-900 mt-0.5">±{result.requiredSessions} sesi</strong>
                            </li>
                            <li className="flex flex-col">
                              <span className="text-gray-400 text-xs">Jumlah Device</span>
                              <strong className="text-gray-900 mt-0.5">{result.recommended.devices} device</strong>
                            </li>
                            <li className="flex flex-col">
                              <span className="text-gray-400 text-xs">Sesi / Device</span>
                              <strong className="text-gray-900 mt-0.5">
                                ±{Math.ceil(result.requiredSessions / result.recommended.devices)} sesi
                              </strong>
                            </li>
                            <li className="flex flex-col">
                              <span className="text-gray-400 text-xs">Durasi Paket / Hari</span>
                              <strong className="text-gray-900 mt-0.5">{result.recommended.hours} Jam</strong>
                            </li>
                            <li className="flex flex-col">
                              <span className="text-gray-400 text-xs">Total Event</span>
                              <strong className="text-gray-900 mt-0.5">{searchedInput.days} Hari</strong>
                            </li>
                            <li className="flex flex-col col-span-2 border-t border-gray-100 pt-3 mt-1">
                              <span className="text-gray-400 text-xs">Total Operasional Photobooth</span>
                              <strong className="text-gray-900 mt-0.5">
                                {result.recommended.devices * result.recommended.hours * searchedInput.days} jam perangkat
                              </strong>
                            </li>
                            <li className="flex flex-col border-t border-gray-100 pt-3 mt-1">
                              <span className="text-gray-400 text-xs">Estimasi Harga / Hari</span>
                              <strong className="text-gray-900 text-base mt-0.5">
                                {formatRupiah(result.recommended.pricePerDay)}
                              </strong>
                            </li>
                            <li className="flex flex-col border-t border-gray-100 pt-3 mt-1">
                              <span className="text-gray-400 text-xs">Total Estimasi Harga ({searchedInput.days} Hari)</span>
                              <strong className="text-primary text-lg mt-0.5">
                                {formatRupiah(result.recommended.totalPrice)}
                              </strong>
                            </li>
                          </ul>
                        </div>

                        {/* CTA WA */}
                        <div className="pt-4 pb-8 relative group">
                          {/* Hover Info Box */}
                          <div className="absolute top-[82px] left-0 right-0 opacity-0 pointer-events-none group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity duration-300 text-center z-10">
                            <span className="inline-block bg-emerald-50 text-emerald-700 text-xs font-semibold py-1.5 px-3.5 rounded-lg border border-emerald-100 shadow-sm">
                              🎉 Book with us and get a special price for bulk orders!
                            </span>
                          </div>

                          <a
                            href={getWAUrl(result.recommended)}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center justify-center gap-3 w-full py-4 rounded-full bg-primary hover:bg-primary-light text-white font-bold transition shadow-lg hover:shadow-xl active:scale-98 cursor-pointer"
                          >
                            <WhatsAppIcon className="w-5 h-5" />
                            Pesan Paket Rekomendasi
                          </a>
                        </div>
                      </div>
                    </div>

                    {/* Alternative Package */}
                    <div className="md:col-span-5 flex flex-col gap-6">
                      {result.alternative ? (
                        <div className="bg-white rounded-2xl border border-gray-200 shadow-lg overflow-hidden flex-grow">
                          <div className="bg-gray-100 px-6 py-4">
                            <span className="text-xs font-bold uppercase tracking-wider text-gray-600 bg-gray-200 px-3 py-1 rounded-md">
                              Alternatif Paket
                            </span>
                          </div>
                          <div className="p-6 space-y-6">
                            <div className="space-y-1">
                              <p className="text-xs text-gray-500 font-semibold uppercase tracking-wider">Per Hari</p>
                              <h3 className="text-2xl font-bold text-gray-800">
                                {result.alternative.devices} Device × {result.alternative.hours} Jam
                              </h3>
                              <p className="text-sm text-gray-600 font-medium">
                                Untuk: <strong className="text-gray-800">{searchedInput.days} Hari Event</strong>
                              </p>
                            </div>

                            <div className="bg-gray-50 border border-gray-100 rounded-xl p-4 text-gray-700 text-sm leading-relaxed">
                              {result.alternative.devices === 1 ? (
                                "Cocok jika ingin menggunakan satu device dengan durasi event yang lebih panjang."
                              ) : (
                                "Gunakan dua device untuk mempercepat antrean jika diperlukan pelayanan yang lebih responsif."
                              )}
                            </div>

                            <div className="border-t border-gray-100 pt-5 text-sm text-gray-700 space-y-2">
                              <div className="flex justify-between">
                                <span className="text-gray-400">Kebutuhan Waktu</span>
                                <strong>±{result.requiredMinutes} menit / hari</strong>
                              </div>
                              <div className="flex justify-between">
                                <span className="text-gray-400">Jumlah Device</span>
                                <strong>{result.alternative.devices} device</strong>
                              </div>
                              <div className="flex justify-between">
                                <span className="text-gray-400">Total Operasional</span>
                                <strong className="text-gray-900">
                                  {result.alternative.devices * result.alternative.hours * searchedInput.days} jam perangkat
                                </strong>
                              </div>
                              <div className="flex justify-between border-t border-gray-50 pt-2">
                                <span className="text-gray-400">Harga / Hari</span>
                                <strong className="text-gray-950">{formatRupiah(result.alternative.pricePerDay)}</strong>
                              </div>
                              <div className="flex justify-between mt-0.5">
                                <span className="text-gray-400 font-medium">Total Harga ({searchedInput.days} Hari)</span>
                                <strong className="text-primary font-bold">{formatRupiah(result.alternative.totalPrice)}</strong>
                              </div>
                            </div>

                            <a
                              href={getWAUrl(result.alternative)}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="inline-flex items-center justify-center gap-2 w-full py-3 rounded-full border border-primary text-primary hover:bg-primary hover:text-white font-semibold transition cursor-pointer"
                            >
                              <WhatsAppIcon className="w-4 h-4" />
                              Pesan Paket Alternatif
                            </a>
                          </div>
                        </div>
                      ) : (
                        <div className="bg-white rounded-2xl border border-gray-200 shadow-md p-6 flex flex-col justify-center items-center text-center h-full">
                          <p className="text-gray-400 mb-2 font-medium">Tidak ada alternatif paket reguler</p>
                          <p className="text-xs text-gray-500 leading-relaxed max-w-xs">
                            Hanya konfigurasi rekomendasi utama yang memenuhi kapasitas waktu untuk melayani seluruh tamu.
                          </p>
                        </div>
                      )}

                      {/* Disclaimer Card */}
                      <div className="bg-gray-100/50 rounded-2xl border border-gray-200/50 p-5">
                        <div className="flex gap-3">
                          <svg
                            className="w-5 h-5 text-gray-400 shrink-0 mt-0.5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                          >
                            <path
                              strokeLinecap="round"
                              strokeLinejoin="round"
                              strokeWidth={2}
                              d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                            />
                          </svg>
                          <p className="text-xs text-gray-500 leading-relaxed">
                            Perhitungan menggunakan jumlah tamu sebagai potensi maksimum pengguna photobooth dan merupakan estimasi berdasarkan rata-rata durasi serta jumlah orang per sesi.
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Comparison Section */}
                  <div className="space-y-6">
                    <div className="text-center md:text-left">
                      <h3 className="text-2xl font-bold text-primary">Kombinasi & Kelayakan Paket</h3>
                      <p className="text-gray-500 text-sm mt-1">
                        Berikut adalah evaluasi kapasitas untuk semua skenario paket reguler Photomate:
                      </p>
                    </div>

                    {/* Table View (Desktop & Tablet) */}
                    <div className="hidden sm:block overflow-x-auto rounded-xl border border-gray-200 shadow-sm bg-white">
                      <table className="min-w-full divide-y divide-gray-200 text-left text-sm text-gray-700">
                        <thead className="bg-gray-50 text-xs font-bold text-gray-500 uppercase tracking-wider">
                          <tr>
                            <th className="px-6 py-4">Paket (Device × Durasi)</th>
                            <th className="px-6 py-4">Estimasi Kapasitas</th>
                            <th className="px-6 py-4">Estimasi Tamu Terlayani</th>
                            <th className="px-6 py-4">Harga / Hari</th>
                            <th className="px-6 py-4">Total Harga ({searchedInput.days} Hari)</th>
                            <th className="px-6 py-4 text-center">Status</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200">
                          {result.combinations.map((comb) => (
                            <tr key={`${comb.devices}-${comb.hours}`} className="hover:bg-gray-50/50">
                              <td className="px-6 py-4 font-semibold text-gray-900">
                                {comb.devices} Device × {comb.hours} Jam
                              </td>
                              <td className="px-6 py-4">
                                ±{comb.sessionCapacity} sesi / hari
                              </td>
                              <td className="px-6 py-4">
                                ±{comb.peopleCapacity} orang / hari
                              </td>
                              <td className="px-6 py-4 font-medium text-gray-900">
                                {formatRupiah(comb.pricePerDay)}
                              </td>
                              <td className="px-6 py-4 font-semibold text-primary">
                                {formatRupiah(comb.totalPrice)}
                              </td>
                              <td className="px-6 py-4 text-center">
                                <span
                                  className={`inline-block px-3 py-1 text-xs font-semibold rounded-full border ${comb.statusColorClass}`}
                                >
                                  {comb.status}
                                </span>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>

                    {/* Card View (Mobile only to prevent overflow) */}
                    <div className="sm:hidden grid gap-4">
                      {result.combinations.map((comb) => (
                        <div
                          key={`${comb.devices}-${comb.hours}`}
                          className="bg-white rounded-xl p-5 border border-gray-200 shadow-sm space-y-4"
                        >
                          <div className="flex items-center justify-between">
                            <span className="font-bold text-gray-900">
                              {comb.devices} Device × {comb.hours} Jam
                            </span>
                            <span
                              className={`px-3 py-1 text-xs font-semibold rounded-full border ${comb.statusColorClass}`}
                            >
                              {comb.status}
                            </span>
                          </div>
                          <div className="grid grid-cols-2 gap-3 text-xs text-gray-600 border-t border-gray-100 pt-3">
                            <div className="flex flex-col">
                              <span className="text-gray-400">Estimasi Kapasitas</span>
                              <strong className="text-gray-800 mt-0.5">±{comb.sessionCapacity} sesi / hari</strong>
                            </div>
                            <div className="flex flex-col">
                              <span className="text-gray-400">Tamu Terlayani</span>
                              <strong className="text-gray-800 mt-0.5">±{comb.peopleCapacity} orang / hari</strong>
                            </div>
                            <div className="flex flex-col border-t border-gray-50 pt-2 col-span-2 space-y-1">
                              <div className="flex justify-between">
                                <span className="text-gray-400">Harga / Hari:</span>
                                <strong className="text-gray-800">{formatRupiah(comb.pricePerDay)}</strong>
                              </div>
                              <div className="flex justify-between">
                                <span className="text-gray-400 font-medium">Total Harga ({searchedInput.days} Hari):</span>
                                <strong className="text-primary font-semibold">{formatRupiah(comb.totalPrice)}</strong>
                              </div>
                            </div>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              )}
            </motion.div>
          )}
        </AnimatePresence>
      </main>

      <Footer />
    </div>
  );
}
