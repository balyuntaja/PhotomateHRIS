const WA_NUMBER = "6287787405280";

const WA_MESSAGE = encodeURIComponent(
  "Hai Photomate 👋 saya ingin melakukan booking photobooth untuk kebutuhan lebih dari 2 hari atau 2 device. Mohon info paket dan penawaran terbaiknya. Terima kasih 🙏",
);

const WA_URL = `https://wa.me/${WA_NUMBER}?text=${WA_MESSAGE}`;

const CallToAction: React.FC = () => {
  return (
    <section className="relative py-20 overflow-hidden">
      {/* Background */}
      <div className="absolute inset-0 bg-primary" />
      <div className="absolute inset-0 bg-black/20 backdrop-blur-xl" />

      <div className="relative container mx-auto px-4">
        <div
          className="
            max-w-4xl mx-auto text-center
            rounded-3xl
            bg-primary/45 backdrop-blur-2xl
            border border-white/20
            shadow-[0_20px_60px_rgba(0,0,0,0.25)]
            px-8 py-14
          "
        >
          <h3 className="text-2xl md:text-3xl lg:text-4xl font-bold text-white mb-4 leading-snug">
            Butuh sewa 2 Device atau Lebih dari 2 Hari?
          </h3>

          <p className="text-white text-lg mb-10">
            Dapatkan penawaran harga khusus untuk kebutuhan multi device atau
            durasi lebih dari 2 hari. Hubungi admin Photomate untuk detail paket
            dan ketersediaan jadwal.
          </p>

          <a
            href={WA_URL}
            target="_blank"
            rel="noopener noreferrer"
            className="
              inline-flex items-center justify-center
              px-10 py-4 rounded-full
              bg-white/90 text-primary font-semibold
              backdrop-blur-md
              transition-all duration-300
              hover:bg-white hover:-translate-y-0.5
              hover:shadow-[0_12px_30px_rgba(0,0,0,0.25)]
            "
          >
            Hubungi Admin via WhatsApp
          </a>
        </div>
      </div>
    </section>
  );
};

export default CallToAction;
