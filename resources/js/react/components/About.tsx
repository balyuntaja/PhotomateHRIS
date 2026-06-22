import aboutImage from "../assets/img/logophotomateblue.png";
import OptimizedImage from "./OptimizedImage";

const checkItems = [
  "Photobooth kekinian berbasis strip photo estetik",
  "Bisa dipesan untuk wedding, event, dan gathering",
  "Cocok untuk aktivasi brand & kolaborasi kafe",
  "Hasil cetak berkualitas tinggi",
  "File digital siap dibagikan (share-ready)",
  "Desain template menyesuaikan tema acara",
];

const About: React.FC = () => {
  return (
    <section id="about" className="py-20 bg-gray-200 scroll-mt-24">
      <div className="container mx-auto px-4">
        {/* Section Title */}
        <h2 className="text-3xl md:text-4xl font-bold text-primary text-center mb-12 relative">
          About Photomate.id
          <span className="block w-20 h-1 bg-primary mx-auto mt-4 rounded-full" />
        </h2>

        <div className="flex flex-col lg:flex-row gap-12 items-center">
          {/* Image */}
          <div className="w-full lg:w-5/12">
            <div className="relative">
              <OptimizedImage
                src={aboutImage}
                alt="Photomate Photobooth"
                width={640}
                height={640}
                className="rounded-lg w-100"
              />
              <div className="absolute -bottom-4 -right-4 w-full h-full border-4 border-primary rounded-lg -z-10" />
            </div>
          </div>

          {/* Content */}
          <div className="w-full lg:w-7/12">
            <div className="space-y-6">
              <h3 className="text-2xl md:text-3xl font-bold text-primary">
                Mengabadikan Momen Jadi Cerita Berharga
              </h3>

              <p className="text-gray-600 leading-relaxed">
                Photomate hadir untuk mengubah momen sederhana menjadi kenangan
                yang berkesan. Kami menyediakan layanan photobooth modern dengan
                sentuhan desain estetik, kualitas cetak premium, dan pengalaman
                yang menyenangkan untuk semua kalangan.
              </p>
              {/* Feature List */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mt-6">
                {checkItems.map((item, index) => (
                  <div key={index} className="flex items-center gap-3">
                    <span className="shrink-0 w-6 h-6 bg-primary/10 rounded-full flex items-center justify-center">
                      <svg
                        className="w-4 h-4 text-primary"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          strokeLinecap="round"
                          strokeLinejoin="round"
                          strokeWidth={2}
                          d="M5 13l4 4L19 7"
                        />
                      </svg>
                    </span>
                    <span className="text-gray-600">{item}</span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};

export default About;
