import { Link } from "react-router-dom";
import { Navbar, Footer } from "../components";
import { pricingPlans } from "../data/pricing";

/* ===============================
   WhatsApp Helper
================================ */
const WA_NUMBER = "6287787405280";

const getWAUrlByPackage = (packageName: string) => {
  const message = encodeURIComponent(
    `Hai Photomate 👋 saya tertarik untuk booking paket SEWA photobooth *${packageName}*. Mohon info ketersediaan jadwal dan penawaran terbaiknya. Terima kasih 🙏`,
  );

  return `https://wa.me/${WA_NUMBER}?text=${message}`;
};

const PricingSewaPage: React.FC = () => {
  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />

      <main className="container mx-auto px-4 pt-28 pb-20 max-w-6xl">
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
        <header className="mb-12">
          <h1 className="text-3xl md:text-4xl font-bold text-primary">
            Pricelist Sewa Photobooth
          </h1>
          <span className="block w-24 h-1 bg-primary mt-4 rounded-full" />
          <p className="text-gray-600 mt-4 max-w-2xl">
            Paket layanan photobooth full service dari Photomate untuk wedding,
            corporate event, ulang tahun, hingga gathering komunitas.
          </p>
        </header>

        {/* Packages */}
        <section>
          <div className="grid md:grid-cols-3 gap-8">
            {pricingPlans.map((plan) => (
              <div
                key={plan.name}
                className={`relative rounded-2xl bg-white p-6 text-center transition-all duration-300 ${
                  plan.isPopular
                    ? "border-2 border-primary shadow-2xl scale-105"
                    : "border border-gray-200 shadow-md hover:shadow-lg"
                }`}
              >
                {plan.isPopular && (
                  <div className="absolute -top-4 left-1/2 -translate-x-1/2">
                    <span className="bg-primary text-white px-4 py-1 rounded-full text-xs font-semibold">
                      Paling Populer
                    </span>
                  </div>
                )}

                <h3 className="text-xl font-bold text-primary mb-1">
                  Paket {plan.name}
                </h3>
                <p className="text-gray-500 mb-3">
                  Durasi operasional {plan.duration}
                </p>

                <div className="mb-5">
                  <p className="text-gray-400 line-through text-sm">
                    Rp {plan.originalPrice}
                  </p>
                  <p className="text-3xl font-bold text-primary">
                    Rp {plan.nettPrice}
                  </p>
                </div>

                <ul className="space-y-2 text-left mb-6 text-gray-700 text-sm">
                  <li>
                    Cetak Foto 4R: <strong>{plan.photo4R}</strong>
                  </li>
                  <li>{plan.designTemplate}</li>
                  <li>Background standard</li>
                  <li>Properti unik & lucu</li>
                  <li>Kru ramah & profesional</li>
                  <li>Semua file via link</li>
                  {plan.features.paperFrame && <li>Paper frame</li>}
                </ul>

                {/* CTA PER PACKAGE */}
                <a
                  href={getWAUrlByPackage(plan.name)}
                  target="_blank"
                  rel="noopener noreferrer"
                  className={`
                    inline-flex items-center justify-center gap-2
                    w-full py-3 rounded-full
                    font-semibold transition-all duration-300
                    ${
                      plan.isPopular
                        ? "bg-primary text-white hover:bg-primary-dark"
                        : "bg-primary/10 text-primary hover:bg-primary hover:text-white"
                    }
                  `}
                >
                  Pesan Paket {plan.name}
                  <svg
                    className="w-5 h-5"
                    viewBox="0 0 24 24"
                    fill="currentColor"
                    aria-hidden
                  >
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347" />
                  </svg>
                </a>

                <p className="mt-4 text-xs text-gray-400">
                  *Harga dapat menyesuaikan lokasi & kebutuhan event.
                </p>
              </div>
            ))}
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
};

export default PricingSewaPage;
