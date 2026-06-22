import { Link } from "react-router-dom";
import { Navbar, Footer } from "../components";
import { selfRunRows } from "../data/pricing";

const WA_NUMBER = "6287787405280";
const WA_MESSAGE = encodeURIComponent(
  "Hai Photomate 👋 saya tertarik dengan Paket Photobooth Self Run. Boleh dibantu info detail dan ketersediaan jadwal?"
);
const WA_URL = `https://wa.me/${WA_NUMBER}?text=${WA_MESSAGE}`;

const PricingSelfRunPage: React.FC = () => {
  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <main className="container mx-auto px-4 pt-28 pb-20 max-w-5xl">
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
        <header className="mb-8">
          <h1 className="text-3xl md:text-4xl font-bold text-primary">
            Paket Photobooth Self Run
          </h1>
          <span className="block w-24 h-1 bg-primary mt-4 rounded-full" />
          <p className="text-gray-600 mt-4 max-w-3xl">
            Sistem di mana panitia event mengelola langsung penjualan sesi foto,
            sementara Photomate menyiapkan perangkat dan setup hingga siap
            pakai.
          </p>
        </header>

        {/* CTA ke WhatsApp */}
        <div className="mb-8">
          <a
            href={WA_URL}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 px-6 py-3 rounded-full bg-primary text-white font-semibold shadow-md hover:bg-primary-dark transition-colors"
          >
            Pesan / Konsultasi Paket Self Run
            <svg
              className="w-5 h-5"
              viewBox="0 0 24 24"
              fill="currentColor"
              aria-hidden
            >
              <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347" />
            </svg>
          </a>
        </div>

        {/* Detail tabel self run */}
        <section className="rounded-3xl bg-white shadow-md p-6 md:p-8 lg:p-10">
          <p className="text-sm md:text-base text-gray-700 mb-4">
            <q className="font-semibold">
              Sewa Layanan, Kelola Penjualan, Keuntungan Milik Event
              Sepenuhnya!
            </q>
          </p>
          <p className="text-sm md:text-base text-gray-600 mb-6 max-w-3xl">
            Photomate menyiapkan unit photobooth, setup, dan dukungan teknis
            hingga alat siap beroperasi. Penjualan sesi foto dan pengelolaan
            antrean sepenuhnya dipegang oleh pihak event.
          </p>

          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 text-sm text-gray-700 mb-6">
            <div>• Desain frame sesuai tema acara</div>
            <div>• Crew pendamping & peralatan lengkap</div>
            <div>• Konsep self-service photobox interaktif</div>
            <div>• Free transport area Malang Raya</div>
          </div>

          <div className="overflow-x-auto">
            <table className="min-w-full text-sm text-left border-separate border-spacing-y-2">
              <thead className="bg-primary text-white">
                <tr>
                  <th className="px-4 py-3 rounded-l-2xl">Durasi (Jam)</th>
                  <th className="px-4 py-3">Harga Sewa</th>
                  <th className="px-4 py-3 rounded-r-2xl">
                    Proyeksi Keuntungan
                  </th>
                </tr>
              </thead>
              <tbody>
                {selfRunRows.map((row) => (
                  <tr
                    key={row.duration}
                    className="bg-gray-50 hover:bg-gray-100 transition-colors"
                  >
                    <td className="px-4 py-2 rounded-l-2xl">{row.duration}</td>
                    <td className="px-4 py-2">{row.price}</td>
                    <td className="px-4 py-2 rounded-r-2xl font-semibold text-primary">
                      {row.profit}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <p className="text-xs text-gray-500 mt-4">
            *Proyeksi keuntungan bersifat estimasi dan dapat berbeda tergantung
            harga jual per sesi di event.
          </p>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default PricingSelfRunPage;

