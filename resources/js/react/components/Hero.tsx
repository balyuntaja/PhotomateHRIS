import { useEffect, useState } from "react";
import heroCollage from "../assets/img/hero_photomate.webp";
import OptimizedImage from "./OptimizedImage";

const DURATION_MS = 1800;
const EVENT_TARGET = 70;
const SESI_TARGET = 2713;

function useCountUp(end: number, startOnMount: boolean) {
  const [value, setValue] = useState(0);

  useEffect(() => {
    if (!startOnMount) return;
    let startTime: number | null = null;
    let rafId: number;

    const step = (timestamp: number) => {
      if (startTime == null) startTime = timestamp;
      const elapsed = timestamp - startTime;
      const progress = Math.min(elapsed / DURATION_MS, 1);
      const easeOut = 1 - Math.pow(1 - progress, 3);
      setValue(Math.round(easeOut * end));
      if (progress < 1) rafId = requestAnimationFrame(step);
    };

    rafId = requestAnimationFrame(step);
    return () => cancelAnimationFrame(rafId);
  }, [end, startOnMount]);

  return value;
}

function formatSesi(n: number) {
  return n.toLocaleString("id-ID");
}

const Hero: React.FC = () => {
  const countEvent = useCountUp(EVENT_TARGET, true);
  const countSesi = useCountUp(SESI_TARGET, true);

  useEffect(() => {
    const existing = document.querySelector<HTMLLinkElement>(
      `link[rel="preload"][href="${heroCollage}"]`
    );
    if (existing) return;

    const preload = document.createElement("link");
    preload.rel = "preload";
    preload.as = "image";
    preload.href = heroCollage;
    document.head.appendChild(preload);
  }, []);

  return (
    <section
      id="home"
      className="relative overflow-hidden bg-linear-to-b from-white via-white to-primary/5 pt-32 pb-16"
    >
      <div className="container mx-auto px-4">
        <div className="grid lg:grid-cols-2 gap-12 items-center">
          
          {/* LEFT — TEXT */}
          <div className="max-w-xl">
            <p className="text-primary font-semibold mb-3 tracking-wide">
              Photobooth Express Malang
            </p>

            <h1 className="text-4xl md:text-5xl lg:text-6xl font-bold text-gray-900 leading-tight mb-6">
              Cetak Foto{" "}
              <span className="bg-linear-to-r from-purple-500 via-orange-400 to-yellow-400 bg-clip-text text-transparent">
                Instant
              </span>{" "}
              untuk Event yang Lebih{" "}
              <span className="bg-linear-to-r from-blue-500 to-pink-500 bg-clip-text text-transparent">
                Berkesan
              </span>
            </h1>

            <p className="text-gray-600 text-lg mb-8 leading-relaxed">
              Photomate menghadirkan pengalaman photobooth modern dengan hasil
              cetak cepat, kualitas HD, dan setup fleksibel untuk berbagai
              kebutuhan event — mulai dari wedding, corporate, hingga event
              komunitas kreatif.
            </p>

            <div className="flex flex-col sm:flex-row gap-4">
              <a
                href="#pricing"
                className="inline-flex items-center justify-center px-8 py-4 rounded-full bg-primary text-white font-semibold hover:bg-primary-light transition shadow-lg"
              >
                Lihat Paket
              </a>

              <a
                href="#gallery"
                className="inline-flex items-center justify-center px-8 py-4 rounded-full border border-gray-300 text-gray-800 font-semibold hover:bg-gray-50 transition"
              >
                Lihat Hasil Foto
              </a>
            </div>
          </div>

          {/* RIGHT — MARQUEE COLLAGE (SIGNATURE) */}
          <div className="relative h-[420px] md:h-[520px] lg:h-[580px] rounded-[28px] overflow-hidden">
            
            {/* glow */}
            <div className="absolute -inset-6 bg-linear-to-tr from-primary/20 via-pink-200/30 to-yellow-200/30 blur-3xl rounded-[40px]" />

            {/* marquee container */}
            <div className="relative h-full w-full overflow-hidden rounded-[28px] bg-white">
              <div className="hero-marquee-inner flex flex-col w-full">
                <OptimizedImage
                  src={heroCollage}
                  alt="Photomate photobooth gallery"
                  width={1080}
                  height={1620}
                  className="w-full h-auto shrink-0 block"
                  critical
                  withSkeleton
                />
              </div>
            </div>

            {/* floating stats */}
            <div className="absolute bottom-4 right-4 mr-3 bg-white rounded-2xl shadow-xl px-6 py-4 border border-gray-100">
              <div className="flex gap-8">
                <div>
                  <p className="text-2xl font-bold text-primary tabular-nums">
                    {countEvent}
                  </p>
                  <p className="text-xs text-gray-500">Event</p>
                </div>
                <div>
                  <p className="text-2xl font-bold text-primary tabular-nums">
                    {formatSesi(countSesi)}
                  </p>
                  <p className="text-xs text-gray-500">Sesi Foto</p>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </section>
  );
};

export default Hero;
