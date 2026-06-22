import whyImage from "../assets/img/achievement.webp";
import OptimizedImage from "./OptimizedImage";

const ARTICLE_URL =
  "https://timesindonesia.co.id/tekno/550852/photomateid-usaha-photobooth-express-karya-gen-z-yang-fleksibel-dan-kreatif";

const WhySection: React.FC = () => {
  return (
    <section id="why" className="bg-white scroll-mt-24">
      <div className="flex flex-col lg:flex-row">
        {/* Image Side */}
        <div className="lg:w-1/2">
          <OptimizedImage
            src={whyImage}
            alt="Photomate di Times Indonesia"
            width={1200}
            height={1200}
            className="w-full h-full object-cover min-h-[400px]"
          />
        </div>

        {/* Content Side */}
        <div className="lg:w-1/2 bg-primary flex items-center">
          <div className="p-8 md:p-12 lg:p-16 text-white w-full">
            {/* Featured in */}
            <p className="text-white/80 text-sm font-medium uppercase tracking-wider mb-2">
              Diberitakan di
            </p>

            <p className="text-xl md:text-2xl font-bold mb-2">
              Times Indonesia
            </p>

            <p className="text-white/90 text-sm mb-6">
              Photomate.id, Usaha Photobooth Express Karya Gen Z yang Fleksibel dan Kreatif
            </p>

            {/* Main summary */}
            <h3 className="text-lg md:text-xl font-bold mb-4">
              Photobooth Modern yang Fleksibel untuk Berbagai Event
            </h3>

            <p className="text-white/90 mb-4 leading-relaxed">
              Photomate.id hadir sebagai inovasi photobooth express yang
              mengedepankan fleksibilitas, kecepatan cetak, dan pengalaman
              interaktif untuk berbagai kebutuhan acara — mulai dari event
              sekolah, brand activation, hingga corporate event.
            </p>

            <p className="text-white/80 mb-8 leading-relaxed">
              Digagas oleh Gen Z kreatif, Photomate membawa pendekatan yang
              lebih modern dan adaptif terhadap tren event masa kini, dengan
              fokus pada kualitas hasil, kemudahan operasional, dan pengalaman
              pengguna yang menyenangkan.
            </p>

            <a
              href={ARTICLE_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-2 px-6 py-3 bg-white text-primary font-semibold rounded-full hover:bg-gray-100 transition-colors duration-300 shadow-lg"
            >
              Baca artikel lengkap
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
                  d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
                />
              </svg>
            </a>
          </div>
        </div>
      </div>
    </section>
  );
};

export default WhySection;
