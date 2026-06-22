import { Link } from "react-router-dom";
import { Navbar, Footer } from "../components";
import { sharingOneDevice, sharingTwoDevice } from "../data/pricing";

const WA_NUMBER = "6287787405280";
const WA_MESSAGE = encodeURIComponent(
  "Hai Photomate 👋 saya tertarik dengan skema Sharing Profit. Boleh dibantu info detail dan simulasi perhitungan untuk event saya?"
);
const WA_URL = `https://wa.me/${WA_NUMBER}?text=${WA_MESSAGE}`;

const PricingSharingProfitPage: React.FC = () => {
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
            Pricelist Sharing Profit
          </h1>
          <span className="block w-24 h-1 bg-primary mt-4 rounded-full" />
          <p className="text-gray-600 mt-4 max-w-3xl">
            Skema bagi hasil antara Photomate dan panitia event. Cocok untuk
            festival, konser, exhibition, dan event dengan traffic pengunjung
            tinggi.
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
            Diskusikan Skema Sharing Profit via WhatsApp
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

        {/* Detail Sharing Profit */}
        <section className="rounded-3xl bg-white shadow-md p-6 md:p-8 lg:p-10">
          <p className="text-sm md:text-base text-gray-700 mb-4">
            <q className="font-semibold">
              Foto Bareng, Untung Bareng, Cuan untuk Semua!
            </q>
          </p>
          <p className="text-sm md:text-base text-gray-600 mb-8 max-w-3xl">
            Dengan skema sharing profit, Photomate menyediakan perangkat dan
            operasional photobooth, sementara pendapatan tiket atau sesi foto
            dibagi berdasarkan kesepakatan. Ideal untuk acara yang ingin
            menambah value sekaligus potensi pemasukan.
          </p>

          <div className="grid gap-8 lg:grid-cols-2">
            {/* 1 Device */}
            <div className="rounded-2xl border border-gray-200 p-6">
              <span className="inline-flex items-center px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-semibold mb-4">
                1 Device
              </span>
              <p className="text-sm text-gray-600 mb-4">
                Durasi layanan maks. <strong>6 jam</strong> dengan{" "}
                <strong>2–3 crew</strong> Photomate di lokasi.
              </p>

              <div className="overflow-x-auto">
                <table className="min-w-full text-sm text-left border-separate border-spacing-y-2">
                  <thead className="bg-primary text-white">
                    <tr>
                      <th className="px-4 py-3 rounded-l-2xl">Skema</th>
                      <th className="px-4 py-3">Jumlah Foto (Sesi)</th>
                      <th className="px-4 py-3">Bagi Hasil</th>
                      <th className="px-4 py-3 rounded-r-2xl">
                        Harga per Sesi
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {sharingOneDevice.map((row) => (
                      <tr
                        key={row.schema}
                        className="bg-gray-50 hover:bg-gray-100 transition-colors"
                      >
                        <td className="px-4 py-2 rounded-l-2xl">
                          {row.schema}
                        </td>
                        <td className="px-4 py-2">{row.sessions}</td>
                        <td className="px-4 py-2">{row.split}</td>
                        <td className="px-4 py-2 rounded-r-2xl">
                          {row.price}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            {/* 2 Device */}
            <div className="rounded-2xl border border-gray-200 p-6">
              <span className="inline-flex items-center px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-semibold mb-4">
                2 Device
              </span>
              <p className="text-sm text-gray-600 mb-4">
                Dua unit photobooth untuk area yang sangat ramai atau antrean
                panjang, dengan durasi layanan maks. <strong>6 jam</strong>.
              </p>

              <div className="overflow-x-auto">
                <table className="min-w-full text-sm text-left border-separate border-spacing-y-2">
                  <thead className="bg-primary text-white">
                    <tr>
                      <th className="px-4 py-3 rounded-l-2xl">Skema</th>
                      <th className="px-4 py-3">Jumlah Foto (Sesi)</th>
                      <th className="px-4 py-3">Bagi Hasil</th>
                      <th className="px-4 py-3 rounded-r-2xl">
                        Harga per Sesi
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {sharingTwoDevice.map((row) => (
                      <tr
                        key={row.schema}
                        className="bg-gray-50 hover:bg-gray-100 transition-colors"
                      >
                        <td className="px-4 py-2 rounded-l-2xl">
                          {row.schema}
                        </td>
                        <td className="px-4 py-2">{row.sessions}</td>
                        <td className="px-4 py-2">{row.split}</td>
                        <td className="px-4 py-2 rounded-r-2xl">
                          {row.price}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <p className="text-xs text-gray-500 mt-6">
            *Catatan: bagi hasil mengacu pada nett profit dengan minimal
            estimasi sesi foto sesuai skema. Detail final dapat dinegosiasikan
            bersama tim Photomate.
          </p>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default PricingSharingProfitPage;

