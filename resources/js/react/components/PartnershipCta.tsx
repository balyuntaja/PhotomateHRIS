import { motion, useReducedMotion, type Variants } from "framer-motion";
import { FiArrowUpRight, FiCamera, FiCoffee } from "react-icons/fi";

const WA_NUMBER = "6287787405280";

const WA_MESSAGE = encodeURIComponent(
  "Halo Photomate! Saya tertarik untuk menjalin kerja sama partnership dengan Photomate untuk cafe/tempat usaha saya. Boleh saya mendapatkan informasi lebih lanjut mengenai skema kerja samanya?",
);

const WA_URL = `https://wa.me/${WA_NUMBER}?text=${WA_MESSAGE}`;

const PartnershipCta: React.FC = () => {
  const shouldReduceMotion = useReducedMotion();

  const fadeUp: Variants = {
    hidden: { opacity: 0, y: shouldReduceMotion ? 0 : 28 },
    visible: {
      opacity: 1,
      y: 0,
      transition: {
        duration: shouldReduceMotion ? 0 : 0.6,
        ease: "easeOut",
      },
    },
  };

  const stagger: Variants = {
    hidden: {},
    visible: {
      transition: {
        staggerChildren: shouldReduceMotion ? 0 : 0.12,
        delayChildren: shouldReduceMotion ? 0 : 0.05,
      },
    },
  };

  return (
    <section
      id="partnership"
      className="relative overflow-hidden bg-primary py-20 md:py-28 scroll-mt-24"
    >
      {/* Decorative background */}
      <div aria-hidden className="pointer-events-none absolute inset-0">
        <div className="absolute -left-24 -top-32 h-80 w-80 rounded-full bg-white/10 blur-3xl" />
        <div className="absolute -bottom-40 -right-24 h-96 w-96 rounded-full bg-[#f8e2b9]/10 blur-3xl" />
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.08)_1px,transparent_0)] bg-[length:28px_28px] opacity-30 [mask-image:radial-gradient(ellipse_at_center,black,transparent_72%)]" />

        <div className="absolute left-[7%] top-1/2 hidden -translate-y-1/2 lg:block">
          <div className="flex h-28 w-28 items-center justify-center rounded-full border border-white/10 bg-white/[0.03]">
            <FiCoffee className="h-10 w-10 text-white/10" />
          </div>
        </div>
        <div className="absolute right-[7%] top-1/2 hidden -translate-y-1/2 lg:block">
          <div className="flex h-36 w-36 items-center justify-center rounded-full border border-white/10 bg-white/[0.03]">
            <FiCamera className="h-12 w-12 text-[#f8e2b9]/15" />
          </div>
        </div>
      </div>

      <div className="relative container mx-auto px-4 md:px-6">
        <motion.div
          variants={stagger}
          initial="hidden"
          whileInView="visible"
          viewport={{ once: true, amount: 0.25 }}
          className="mx-auto max-w-3xl text-center"
        >
          {/* Collaboration emblem + eyebrow */}
          <motion.div variants={fadeUp} className="flex flex-col items-center">
            <div className="flex -space-x-3" aria-hidden>
              <span className="flex h-12 w-12 items-center justify-center rounded-2xl border border-white/15 bg-white/10 text-white backdrop-blur-sm">
                <FiCoffee className="h-5 w-5" />
              </span>
              <span className="flex h-12 w-12 items-center justify-center rounded-2xl border border-[#f8e2b9]/40 bg-[#f8e2b9] text-primary shadow-[0_10px_30px_rgba(0,0,0,0.2)]">
                <FiCamera className="h-5 w-5" />
              </span>
            </div>

            <span className="mt-6 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-white/80 backdrop-blur-sm">
              <span
                aria-hidden
                className="h-1.5 w-1.5 rounded-full bg-[#f8e2b9]"
              />
              PHOTOMATE PARTNERSHIP
            </span>
          </motion.div>

          {/* Headline */}
          <motion.div variants={fadeUp}>
            <h2 className="mt-6 text-4xl font-extrabold leading-[1.05] tracking-tight text-white sm:text-5xl lg:text-6xl">
              Let's Grow Together!
            </h2>
            <p className="mt-4 text-lg font-semibold text-[#f8e2b9] sm:text-xl">
              Bring Photomate to Your Cafe.
            </p>
            <p className="mx-auto mt-5 max-w-2xl text-sm leading-relaxed text-white/75 sm:text-base">
              Punya cafe atau tempat usaha dan tertarik menghadirkan pengalaman
              photobooth untuk pelanggan Anda? Mari bangun kolaborasi yang
              saling menguntungkan bersama Photomate.
            </p>
          </motion.div>

          {/* CTA */}
          <motion.div variants={fadeUp} className="mt-9">
            <a
              href={WA_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="group inline-flex items-center justify-center gap-2 rounded-full bg-[#f8e2b9] px-8 py-4 text-sm font-bold text-primary shadow-[0_12px_30px_rgba(0,0,0,0.25)] transition-all duration-300 hover:-translate-y-0.5 hover:bg-[#fbeacb] hover:shadow-[0_18px_40px_rgba(0,0,0,0.3)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#f8e2b9]/60 focus-visible:ring-offset-2 focus-visible:ring-offset-primary active:translate-y-0 sm:text-base"
            >
              Partner With Us
              <FiArrowUpRight className="h-4 w-4 transition-transform duration-300 group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
            </a>
          </motion.div>

          {/* Tagline */}
          <motion.p
            variants={fadeUp}
            className="mt-12 text-[11px] font-medium uppercase tracking-[0.18em] text-white/50 sm:text-xs"
          >
            Photomate — Capture Moments, Create Experiences.
          </motion.p>
        </motion.div>
      </div>
    </section>
  );
};

export default PartnershipCta;
