import React from "react";
import { Link } from "react-router-dom";

const Pricing: React.FC = () => {
  return (
    <section id="pricing" className="py-20 bg-gray-50 scroll-mt-24">
      <div className="container mx-auto px-4 max-w-6xl">
        {/* Section Title */}
        <div className="text-center mb-12">
          <h2 className="text-3xl md:text-4xl font-bold text-primary">
            Paket Layanan
          </h2>
          <span className="block w-24 h-1 bg-primary mx-auto mt-4 rounded-full" />
          <p className="text-gray-600 mt-4 max-w-2xl mx-auto">
            Pilih skema kerja sama yang paling cocok untuk format acara dan
            kebutuhan penjualanmu.
          </p>
        </div>

        {/* Tiga kartu utama: Sewa, Self Run, Sharing Profit */}
        <div className="grid gap-6 md:grid-cols-3 mb-16">
          {/* Pricelist Sewa */}
          <Link
            to="/pricing/sewa"
            className="group rounded-2xl bg-white p-6 md:p-7 shadow-md hover:shadow-xl transition-all duration-300 hover:-translate-y-1 flex flex-col justify-between focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          >
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-primary/80 mb-2">
                Sewa Full Service
              </p>
              <h3 className="text-xl md:text-2xl font-bold text-primary mb-2">
                Pricelist Sewa
              </h3>
              <p className="text-sm text-gray-600 mb-4">
                Paket photobooth lengkap dengan kru Photomate. Cocok untuk
                wedding, corporate event, dan acara formal lainnya.
              </p>
              <ul className="text-xs md:text-sm text-gray-600 space-y-1.5">
                <li>• Durasi fleksibel 2–4 jam</li>
                <li>• Unlimited cetak 4R & desain frame custom</li>
                <li>• Crew profesional & properti foto lengkap</li>
              </ul>
            </div>
            <span className="mt-5 inline-flex items-center justify-between rounded-full bg-primary/5 px-4 py-2 text-sm font-semibold text-primary group-hover:bg-primary group-hover:text-white transition-colors">
              Lihat detail sewa
              <svg
                className="w-4 h-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M9 5l7 7-7 7"
                />
              </svg>
            </span>
          </Link>

          {/* Self Run */}
          <Link
            to="/pricing/self-run"
            className="group rounded-2xl bg-white p-6 md:p-7 shadow-md hover:shadow-xl transition-all duration-300 hover:-translate-y-1 flex flex-col justify-between focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          >
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-primary/80 mb-2">
                Sewa + Kelola Penjualan Sendiri
              </p>
              <h3 className="text-xl md:text-2xl font-bold text-primary mb-2">
                Paket Photobooth Self Run
              </h3>
              <p className="text-sm text-gray-600 mb-4">
                Photomate siapkan alat & setup, pihak event mengelola langsung
                penjualan sesi foto dan keuntungannya.
              </p>
              <ul className="text-xs md:text-sm text-gray-600 space-y-1.5">
                <li>• Konsep self-service photobox interaktif</li>
                <li>• Desain frame sesuai tema acara</li>
                <li>• Free transport area Malang Raya</li>
              </ul>
            </div>
            <span className="mt-5 inline-flex items-center justify-between rounded-full bg-primary/5 px-4 py-2 text-sm font-semibold text-primary group-hover:bg-primary group-hover:text-white transition-colors">
              Lihat skema Self Run
              <svg
                className="w-4 h-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M9 5l7 7-7 7"
                />
              </svg>
            </span>
          </Link>

          {/* Sharing Profit */}
          <Link
            to="/pricing/sharing-profit"
            className="group rounded-2xl bg-white p-6 md:p-7 shadow-md hover:shadow-xl transition-all duration-300 hover:-translate-y-1 flex flex-col justify-between focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          >
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-primary/80 mb-2">
                Bagi Hasil
              </p>
              <h3 className="text-xl md:text-2xl font-bold text-primary mb-2">
                Pricelist Sharing Profit
              </h3>
              <p className="text-sm text-gray-600 mb-4">
                Skema bagi hasil fleksibel untuk event dengan penjualan tiket
                atau sesi foto di lokasi.
              </p>
              <ul className="text-xs md:text-sm text-gray-600 space-y-1.5">
                <li>• Opsi 1 device dan 2 device</li>
                <li>• Durasi layanan hingga 6 jam</li>
                <li>• Crew Photomate 2–3 orang</li>
              </ul>
            </div>
            <span className="mt-5 inline-flex items-center justify-between rounded-full bg-primary/5 px-4 py-2 text-sm font-semibold text-primary group-hover:bg-primary group-hover:text-white transition-colors">
              Lihat skema Sharing Profit
              <svg
                className="w-4 h-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M9 5l7 7-7 7"
                />
              </svg>
            </span>
          </Link>
        </div>
      </div>
    </section>
  );
};

export default Pricing;
