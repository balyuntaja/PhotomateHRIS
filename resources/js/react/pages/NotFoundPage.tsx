import { Link } from "react-router-dom";

const WA_NUMBER = "6287787405280";
const WA_URL = `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent("Hai Photomate 👋 saya butuh bantuan.")}`;

export default function NotFoundPage() {
  return (
    <div className="min-h-screen bg-linear-to-b from-primary/5 to-white">
      {/* Decorative shapes */}
      <div className="pointer-events-none fixed inset-0 overflow-hidden">
        <div className="absolute -top-40 -right-40 h-96 w-96 rounded-full bg-primary/15 blur-3xl" />
        <div className="absolute top-1/2 -left-32 h-80 w-80 rounded-full bg-primary-light/20 blur-3xl" />
      </div>


      <main className="relative container mx-auto px-4 pt-12 pb-20 text-center max-w-4xl">
        {/* 404 */}
        <h1 className="text-8xl md:text-9xl font-bold text-primary leading-none">
          404
        </h1>

        <h2 className="mt-4 text-2xl md:text-3xl font-bold text-gray-900">
          Oops!{" "}
          <span className="text-primary border-b-2 border-primary/30">
            Halaman Tidak Ditemukan
          </span>
        </h2>

        <p className="mt-6 text-gray-600 text-lg max-w-xl mx-auto">
          Sepertinya halaman yang Anda cari sudah berpindah atau tidak ada. Tapi
          jangan khawatir, mari kita bantu Anda menemukan jalan kembali ke{" "}
          <span className="text-primary font-semibold">
            momen spesial dengan photobooth
          </span>
          .
        </p>

        {/* CTA Cards */}
        <div className="mt-14 grid grid-cols-1 md:grid-cols-3 gap-6">
          {/* Kembali ke Beranda */}
          <div className="bg-white rounded-2xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 flex flex-col items-center text-center">
            <div className="w-14 h-14 rounded-full bg-primary/10 flex items-center justify-center mb-4">
              <svg
                className="w-7 h-7 text-primary"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
                />
              </svg>
            </div>
            <h3 className="text-lg font-bold text-gray-900 mb-2">
              Kembali ke Beranda
            </h3>
            <p className="text-sm text-gray-600 mb-5">
              Mulai dari awal dan jelajahi semua layanan kami
            </p>
            <Link
              to="/"
              className="w-full py-3 px-4 rounded-xl bg-primary text-white font-semibold hover:bg-primary-dark transition-colors"
            >
              Kembali ke Beranda
            </Link>
          </div>

          {/* Jelajahi Gallery */}
          <div className="bg-white rounded-2xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 flex flex-col items-center text-center">
            <div className="w-14 h-14 rounded-full bg-primary/10 flex items-center justify-center mb-4">
              <svg
                className="w-7 h-7 text-primary"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                />
              </svg>
            </div>
            <h3 className="text-lg font-bold text-gray-900 mb-2">
              Jelajahi Gallery
            </h3>
            <p className="text-sm text-gray-600 mb-5">
              Lihat hasil photobooth dari berbagai acara
            </p>
            <Link
              to="/#gallery"
              className="w-full py-3 px-4 rounded-xl border-2 border-gray-200 text-gray-800 font-semibold hover:border-primary hover:text-primary transition-colors"
            >
              Jelajahi Gallery
            </Link>
          </div>

          {/* Bantuan */}
          <div className="bg-white rounded-2xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 flex flex-col items-center text-center">
            <div className="w-14 h-14 rounded-full bg-primary/10 flex items-center justify-center mb-4">
              <svg
                className="w-7 h-7 text-primary"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                />
              </svg>
            </div>
            <h3 className="text-lg font-bold text-gray-900 mb-2">Bantuan</h3>
            <p className="text-sm text-gray-600 mb-5">
              Dapatkan bantuan dari tim Photomate via WhatsApp
            </p>
            <a
              href={WA_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="w-full py-3 px-4 rounded-xl border-2 border-gray-200 text-gray-800 font-semibold hover:border-primary hover:text-primary transition-colors"
            >
              Hubungi WhatsApp
            </a>
          </div>
        </div>
      </main>
    </div>
  );
}
