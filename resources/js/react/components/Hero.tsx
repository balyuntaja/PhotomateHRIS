import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { motion, AnimatePresence } from "framer-motion";
import heroCollage from "../assets/img/hero_photomate.webp";
import OptimizedImage from "./OptimizedImage";

const DURATION_MS = 1800;
const EVENT_TARGET = 70;
const SESI_TARGET = 2713;

function useCountUp(end: number, startOnMount: boolean) {
  const [value, setValue] = useState(0);

  useEffect(() => {
    if (!startOnMount) return;
    let startTime: number | null = null;
    let rafId: number;

    const step = (timestamp: number) => {
      if (startTime == null) startTime = timestamp;
      const elapsed = timestamp - startTime;
      const progress = Math.min(elapsed / DURATION_MS, 1);
      const easeOut = 1 - Math.pow(1 - progress, 3);
      setValue(Math.round(easeOut * end));
      if (progress < 1) rafId = requestAnimationFrame(step);
    };

    rafId = requestAnimationFrame(step);
    return () => cancelAnimationFrame(rafId);
  }, [end, startOnMount]);

  return value;
}

function formatSesi(n: number) {
  return n.toLocaleString("id-ID");
}

const Hero: React.FC = () => {
  const countEvent = useCountUp(EVENT_TARGET, true);
  const countSesi = useCountUp(SESI_TARGET, true);
  const [showExplanationModal, setShowExplanationModal] = useState(false);

  useEffect(() => {
    const existing = document.querySelector<HTMLLinkElement>(
      `link[rel="preload"][href="${heroCollage}"]`
    );
    if (existing) return;

    const preload = document.createElement("link");
    preload.rel = "preload";
    preload.as = "image";
    preload.href = heroCollage;
    document.head.appendChild(preload);
  }, []);

  return (
    <section
      id="home"
      className="relative overflow-hidden bg-linear-to-b from-white via-white to-primary/5 pt-32 pb-16"
    >
      <div className="container mx-auto px-4">
        <div className="grid lg:grid-cols-2 gap-12 items-center">
          
          {/* LEFT — TEXT */}
          <div className="max-w-xl">
            <p className="text-primary font-semibold mb-3 tracking-wide">
              Photobooth Express Malang
            </p>

            <h1 className="text-4xl md:text-5xl lg:text-6xl font-bold text-gray-900 leading-tight mb-6">
              Cetak Foto{" "}
              <span className="bg-linear-to-r from-purple-500 via-orange-400 to-yellow-400 bg-clip-text text-transparent">
                Instant
              </span>{" "}
              untuk Event yang Lebih{" "}
              <span className="bg-linear-to-r from-blue-500 to-pink-500 bg-clip-text text-transparent">
                Berkesan
              </span>
            </h1>

            <p className="text-gray-600 text-lg mb-8 leading-relaxed">
              Photomate menghadirkan pengalaman photobooth modern dengan hasil
              cetak cepat, kualitas HD, dan setup fleksibel untuk berbagai
              kebutuhan event — mulai dari wedding, corporate, hingga event
              komunitas kreatif.
            </p>

            <div className="flex flex-col gap-4">
              <div className="flex flex-col sm:flex-row gap-4">
                <a
                  href="#pricing"
                  className="inline-flex items-center justify-center px-8 py-4 rounded-full bg-primary text-white font-semibold hover:bg-primary-light transition shadow-lg w-full sm:w-auto"
                >
                  Lihat Paket
                </a>

                <a
                  href="#gallery"
                  className="inline-flex items-center justify-center px-8 py-4 rounded-full border border-gray-300 text-gray-800 font-semibold hover:bg-gray-50 transition w-full sm:w-auto"
                >
                  Lihat Hasil Foto
                </a>
              </div>

              <div className="flex">
                <button
                  type="button"
                  onClick={() => setShowExplanationModal(true)}
                  className="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-full bg-primary/10 text-primary font-semibold hover:bg-primary/20 transition-all text-center w-full sm:w-auto cursor-pointer"
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
                      strokeWidth={2.5}
                      d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                    />
                  </svg>
                  Rekomendasi Paket Photobooth
                </button>
              </div>
            </div>
          </div>

          {/* RIGHT — MARQUEE COLLAGE (SIGNATURE) */}
          <div className="relative h-[420px] md:h-[520px] lg:h-[580px] rounded-[28px] overflow-hidden">
            
            {/* glow */}
            <div className="absolute -inset-6 bg-linear-to-tr from-primary/20 via-pink-200/30 to-yellow-200/30 blur-3xl rounded-[40px]" />

            {/* marquee container */}
            <div className="relative h-full w-full overflow-hidden rounded-[28px] bg-white">
              <div className="hero-marquee-inner flex flex-col w-full">
                <OptimizedImage
                  src={heroCollage}
                  alt="Photomate photobooth gallery"
                  width={600}
                  height={1625}
                  className="w-full h-auto shrink-0 block"
                  critical
                  withSkeleton
                />
              </div>
            </div>

            {/* floating stats */}
            <div className="absolute bottom-4 right-4 mr-3 bg-white rounded-2xl shadow-xl px-6 py-4 border border-gray-100">
              <div className="flex gap-8">
                <div>
                  <p className="text-2xl font-bold text-primary tabular-nums">
                    {countEvent}
                  </p>
                  <p className="text-xs text-gray-500">Event</p>
                </div>
                <div>
                  <p className="text-2xl font-bold text-primary tabular-nums">
                    {formatSesi(countSesi)}
                  </p>
                  <p className="text-xs text-gray-500">Sesi Foto</p>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
      {/* Explanation Modal */}
      <AnimatePresence>
        {showExplanationModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            {/* Backdrop */}
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              onClick={() => setShowExplanationModal(false)}
              className="absolute inset-0 bg-black/50 backdrop-blur-xs"
            />

            {/* Modal Content */}
            <motion.div
              initial={{ opacity: 0, scale: 0.95, y: 10 }}
              animate={{ opacity: 1, scale: 1, y: 0 }}
              exit={{ opacity: 0, scale: 0.95, y: 10 }}
              className="relative bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-gray-100 overflow-hidden z-10 p-6 md:p-8"
            >
              {/* Close Button */}
              <button
                onClick={() => setShowExplanationModal(false)}
                className="absolute top-4 right-4 text-gray-400 hover:text-gray-600 transition p-1.5 rounded-lg hover:bg-gray-50 cursor-pointer"
                aria-label="Tutup"
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>

              {/* Title & Icon */}
              <div className="flex items-center gap-3 mb-5">
                <div className="w-12 h-12 rounded-xl bg-primary/10 flex items-center justify-center text-primary shrink-0">
                  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                    />
                  </svg>
                </div>
                <div>
                  <h3 className="text-xl font-bold text-gray-900">Kalkulator Kebutuhan Paket</h3>
                  <p className="text-xs text-gray-500 font-medium">Bantu Hitung Efisiensi Photobooth Event Kamu</p>
                </div>
              </div>

              {/* Body */}
              <div className="space-y-4 text-sm text-gray-600 leading-relaxed mb-6">
                <p>
                  Setiap event memiliki karakteristik yang unik. Kalkulator ini membantu Anda menentukan konfigurasi paket terbaik secara otomatis berdasarkan:
                </p>
                <ul className="space-y-3 bg-gray-50 p-4 rounded-xl border border-gray-100">
                  <li className="flex gap-2">
                    <span className="text-primary font-bold">⏱️</span>
                    <span><strong>Durasi Operasional:</strong> Menghitung berapa jam photobooth harus standby agar mencukupi kebutuhan seluruh tamu.</span>
                  </li>
                  <li className="flex gap-2">
                    <span className="text-primary font-bold">🖥️</span>
                    <span><strong>Jumlah Device (Alat):</strong> Merekomendasikan apakah butuh 1 atau 2 unit agar antrean tamu tidak menumpuk terlalu panjang.</span>
                  </li>
                  <li className="flex gap-2">
                    <span className="text-primary font-bold">📊</span>
                    <span><strong>Komparasi Paket:</strong> Menyajikan kelayakan 6 opsi kombinasi paket reguler (Kurang, Pas, Berlebih).</span>
                  </li>
                </ul>
                <p className="text-xs text-gray-400">
                  *Perhitungan menggunakan simulasi estimasi sesi rata-rata tanpa menggunakan buffer waktu tambahan.
                </p>
              </div>

              {/* Actions */}
              <div className="flex flex-col sm:flex-row gap-3 justify-end border-t border-gray-100 pt-5">
                <button
                  type="button"
                  onClick={() => setShowExplanationModal(false)}
                  className="px-5 py-2.5 rounded-full border border-gray-200 text-gray-700 font-semibold hover:bg-gray-50 transition text-center cursor-pointer text-sm"
                >
                  Batal
                </button>
                <Link
                  to="/rekomendasi-paket"
                  onClick={() => setShowExplanationModal(false)}
                  className="px-6 py-2.5 rounded-full bg-primary hover:bg-primary-light text-white font-semibold transition text-center cursor-pointer text-sm shadow-md hover:shadow-lg flex items-center justify-center gap-2"
                >
                  Mulai Hitung Rekomendasi
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 5l7 7-7 7" />
                  </svg>
                </Link>
              </div>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </section>
  );
};

export default Hero;
