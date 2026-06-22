interface Testimonial {
  quote: string;
  name: string;
  roleOrEvent: string;
  rating: number;
}

const testimonials: Testimonial[] = [
  {
    quote:
      "Kita berdua happy banget, photobooth-nya high demand. Hasilnya super HD, crew-nya helpful juga. Semoga selalu berkah usahanya ya kak!",
    name: "Rania & Cahya",
    roleOrEvent: "Wedding Client",
    rating: 5,
  },
  {
    quote:
      "Makasih banyak sudah memeriahkan nikahan kami. Teman-teman photomate sangat helpful dan santun. Tamu juga pada senang fotonya. Sukses selalu ya kak!",
    name: "Yasmin & Chicco",
    roleOrEvent: "Wedding Client",
    rating: 5,
  },
  {
    quote:
      "Terima kasih atas kerja sama yang sangat keren dari Kakak dan tim. Wedding dream mempelai terwujud. Sampai bertemu di next event ya!",
    name: "Saraya Organizer",
    roleOrEvent: "Wedding Organizer",
    rating: 5,
  },
  {
    quote:
      "Team Photomate super helpful, agile, dan tidak ribet. Bisa eksekusi brief dengan sangat rapi. Komunikatif dan selalu on time. Recommended!",
    name: "Kak Rizka",
    roleOrEvent: "Lokanesia",
    rating: 5,
  },
  {
    quote:
      "Kerja sama bareng Photomate benar-benar seru. Timnya fast response dan profesional. Banyak peserta puas dengan hasil fotonya. Semoga bisa kolaborasi lagi!",
    name: "Sofi",
    roleOrEvent: "Panitia PKKMB FISIP UB 2025",
    rating: 5,
  },
];

const ClientFeedback: React.FC = () => {
  return (
    <section id="client-feedback" className="py-20 bg-white scroll-mt-24">
      <div className="container mx-auto px-4">
        {/* Section Title */}
        <h2 className="text-3xl md:text-4xl font-bold text-primary text-center mb-4">
          Client Feedback
        </h2>
        <p className="text-gray-600 text-center max-w-2xl mx-auto mb-12">
          Apa kata mereka yang sudah menggunakan layanan Photomate
        </p>
        <span className="block w-20 h-1 bg-primary mx-auto mb-12 rounded-full" />

        {/* Testimonials Row */}
        <div className="flex gap-5 overflow-x-auto pb-4 snap-x snap-mandatory scrollbar-hide">
          {testimonials.map((item, index) => (
            <article
              key={index}
              className="flex flex-col min-w-[300px] md:min-w-[320px] lg:min-w-[340px] w-[300px] md:w-[320px] lg:w-[340px] bg-white p-6 rounded-2xl shadow-md hover:shadow-lg transition-shadow duration-300 border border-gray-100 snap-start shrink-0"
            >
              {/* Rating */}
              <div className="flex gap-0.5 mb-4">
                <span className="sr-only">{item.rating} bintang</span>
                {Array.from({ length: item.rating }).map((_, i) => (
                  <svg
                    key={i}
                    className="w-5 h-5 text-amber-400 shrink-0"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                    aria-hidden
                  >
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                  </svg>
                ))}
              </div>

              {/* Quote */}
              <p className="text-gray-600 text-sm md:text-base leading-relaxed mb-5 min-h-30 line-clamp-5">
                &ldquo;{item.quote}&rdquo;
              </p>

              {/* Author */}
              <div className="pt-4 border-t border-gray-100">
                <p className="font-semibold text-gray-800 text-sm">{item.name}</p>
                <p className="text-xs text-primary mt-0.5">{item.roleOrEvent}</p>
              </div>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
};

export default ClientFeedback;
