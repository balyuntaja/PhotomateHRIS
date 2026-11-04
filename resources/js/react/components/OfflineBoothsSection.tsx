
import React, { useRef, useState } from "react";
import golioBooth from "../assets/img/golio_booth.webp";
import newspaperBooth from "../assets/img/newspaper_booth.webp";
import OptimizedImage from "./OptimizedImage";

type BoothCardProps = {
  image?: string;
  emoji: string;
  hubName: string;
  title: string;
  location: string;
  badge: string;
  directionsUrl?: string;
  variant?: "default" | "soon" | "collaborate";
};

function LocationPinIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M12 21s-7-4.5-7-11a7 7 0 0 1 14 0c0 6.5-7 11-7 11Z" />
      <path d="M12 10a2.2 2.2 0 1 0 0-.01Z" />
    </svg>
  );
}

function BoothCard({
  image,
  emoji,
  hubName,
  title,
  location,
  badge,
  directionsUrl,
  variant = "default",
}: BoothCardProps) {
  const isSoon = variant === "soon";
  const isCollaborate = variant === "collaborate";

  return (
    <div
      className="
        group h-full rounded-[28px]
        border border-primary/10 bg-white/80 backdrop-blur-xl
        shadow-sm transition-all duration-300
        hover:shadow-[0_20px_50px_rgba(54,78,113,0.18)]
        hover:-translate-y-1
      "
    >
      <div className="p-5 md:p-6">
        {/* Image / Illustration */}
        <div className="relative aspect-16/10 overflow-hidden rounded-3xl bg-primary/5">
          {image ? (
            <OptimizedImage
              src={image}
              alt={title}
              width={640}
              height={400}
              className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
              withSkeleton
            />
          ) : (
            <div
              className={`flex h-full w-full items-center justify-center ${isCollaborate
                  ? "bg-linear-to-br from-primary to-[#7186a5]"
                  : "bg-linear-to-br from-[#e9edf4] to-[#d6deea]"
                }`}
            >
              <div className="text-center">
                <span className="text-6xl">
                  {isCollaborate ? "🤝" : "✨"}
                </span>
                <p
                  className={`mt-3 text-sm font-semibold tracking-widest ${isCollaborate ? "text-white/80" : "text-primary/70"
                    }`}
                >
                  {isCollaborate ? "GROW WITH PHOTOMATE" : "SOMETHING NEW"}
                </p>
              </div>
            </div>
          )}
        </div>

        <div className="mt-5 flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="flex items-center gap-2 text-sm font-semibold text-primary/80">
              <span className="text-lg">{emoji}</span>
              <span className="truncate">{hubName}</span>
            </p>

            <h3 className="mt-2 text-xl md:text-2xl font-bold text-gray-900 leading-snug">
              {title}
            </h3>
          </div>

          <span className="shrink-0 rounded-full border border-primary/15 bg-primary/5 px-3 py-1 text-[10px] font-bold tracking-wider text-primary/80">
            {badge}
          </span>
        </div>

        <div className="mt-4 flex items-center gap-2 text-gray-600">
          <LocationPinIcon className="h-4 w-4 shrink-0 text-primary" />
          <p className="text-sm">{location}</p>
        </div>

        <div className="mt-6">
          {isCollaborate ? (
            <a
              href="https://wa.me/6287787405280?text=Photomate%20Collaboration"
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex w-full items-center justify-center rounded-full bg-primary px-6 py-3 font-semibold text-white transition-all hover:bg-primary-light hover:shadow-lg"
            >
              Let's Talk
              <span className="ml-2">↗</span>
            </a>
          ) : isSoon ? (
            <div className="flex w-full items-center justify-center rounded-full border border-primary/15 bg-primary/5 px-6 py-3 font-semibold text-primary/70">
              Stay Tuned
              <span className="ml-2">✦</span>
            </div>
          ) : (
            <a
              href={directionsUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex w-full items-center justify-center rounded-full bg-primary px-6 py-3 font-semibold text-white transition-all hover:bg-primary-light hover:shadow-lg"
            >
              Get Directions
              <span className="ml-2">→</span>
            </a>
          )}
        </div>
      </div>
    </div>
  );
}

const OfflineBoothsSection: React.FC = () => {
  const sliderRef = useRef<HTMLDivElement>(null);
  const [activeIndex, setActiveIndex] = useState(0);

  const scrollSlider = (direction: "prev" | "next") => {
    if (!sliderRef.current) return;

    const container = sliderRef.current;
    const card = container.querySelector<HTMLElement>("[data-booth-card]");
    if (!card) return;

    const gap = 24;
    const distance = card.offsetWidth + gap;

    container.scrollBy({
      left: direction === "next" ? distance : -distance,
      behavior: "smooth",
    });
  };

  const handleScroll = () => {
    if (!sliderRef.current) return;

    const card = sliderRef.current.querySelector<HTMLElement>(
      "[data-booth-card]"
    );

    if (!card) return;

    const gap = 24;
    const index = Math.round(
      sliderRef.current.scrollLeft / (card.offsetWidth + gap)
    );

    setActiveIndex(Math.min(index, 3));
  };

  const booths: BoothCardProps[] = [
    {
      image: golioBooth,
      emoji: "📸",
      hubName: "Golio Hub",
      title: "Photobox Experience",
      location: "Kepanjen, Malang",
      badge: "NOW OPEN",
      directionsUrl:
        "https://www.google.com/maps/search/?api=1&query=Golio+Hub%2C+Kepanjen%2C+Malang",
    },
    {
      image: newspaperBooth,
      emoji: "📰",
      hubName: "Janus Coffee",
      title: "Newspaper Photobooth",
      location: "Kota Batu",
      badge: "NOW OPEN",
      directionsUrl:
        "https://www.google.com/maps/search/?api=1&query=Janus+Coffee%2C+Kota+Batu",
    },
    {
      emoji: "✨",
      hubName: "Photomate Expansion",
      title: "Something New Is Coming",
      location: "New location coming soon",
      badge: "COMING SOON",
      variant: "soon",
    },
    {
      emoji: "🤝",
      hubName: "Be Our Partner",
      title: "Your Cafe, Our Photobooth",
      location: "Let's create something together",
      badge: "OPEN FOR PARTNERSHIP",
      variant: "collaborate",
    },
  ];

  return (
    <section
      id="offline"
      className="relative overflow-hidden bg-linear-to-b from-white via-gray-50/70 to-primary/5 py-16 md:py-20 scroll-mt-24"
    >
      <div className="absolute -top-16 left-1/2 h-72 w-72 -translate-x-1/2 rounded-full bg-primary/10 blur-3xl" />
      <div className="absolute -bottom-20 -left-10 h-72 w-72 rounded-full bg-pink-200/25 blur-3xl" />
      <div className="absolute -bottom-20 -right-10 h-72 w-72 rounded-full bg-yellow-200/25 blur-3xl" />

      <div className="relative container mx-auto px-4 md:px-6">
        <div className="mx-auto max-w-3xl text-center">
          <h2 className="text-3xl md:text-4xl font-extrabold tracking-tight text-gray-900">
            Find Photomate Offline <span aria-hidden>📍</span>
          </h2>
          <p className="mt-4 text-base leading-relaxed text-gray-600 md:text-lg">
            Not only for events — you can now experience Photomate at our
            permanent locations.
          </p>
        </div>

        {/* Slider */}
        <div className="relative mt-10 md:mt-14">
          <div
            ref={sliderRef}
            onScroll={handleScroll}
            className="
              flex gap-6 overflow-x-auto snap-x snap-mandatory
              scroll-smooth pb-5
              [scrollbar-width:none]
              [&::-webkit-scrollbar]:hidden
            "
          >
            {booths.map((booth, index) => (
              <div
                key={booth.hubName}
                data-booth-card
                className="
                  w-[88%] shrink-0 snap-start
                  sm:w-[70%] md:w-[calc(50%-12px)]
                  lg:w-[calc(50%-12px)]
                "
              >
                <BoothCard {...booth} />
              </div>
            ))}
          </div>

          {/* Navigation */}
          <div className="mt-5 flex items-center justify-between">
            <div className="flex items-center gap-2">
              {booths.map((_, index) => (
                <button
                  key={index}
                  onClick={() => {
                    const card = sliderRef.current?.querySelectorAll(
                      "[data-booth-card]"
                    )[index] as HTMLElement | undefined;

                    card?.scrollIntoView({
                      behavior: "smooth",
                      block: "nearest",
                      inline: "start",
                    });
                  }}
                  aria-label={`Go to slide ${index + 1}`}
                  className={`h-2 rounded-full transition-all ${activeIndex === index
                      ? "w-7 bg-primary"
                      : "w-2 bg-primary/20 hover:bg-primary/50"
                    }`}
                />
              ))}
            </div>

            <div className="flex gap-3">
              <button
                onClick={() => scrollSlider("prev")}
                aria-label="Previous booth"
                className="flex h-11 w-11 items-center justify-center rounded-full border border-primary/15 bg-white text-primary transition hover:bg-primary hover:text-white"
              >
                ←
              </button>

              <button
                onClick={() => scrollSlider("next")}
                aria-label="Next booth"
                className="flex h-11 w-11 items-center justify-center rounded-full border border-primary/15 bg-white text-primary transition hover:bg-primary hover:text-white"
              >
                →
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};

export default OfflineBoothsSection;
