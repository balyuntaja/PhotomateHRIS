import React from "react";

type BoothCardProps = {
  emoji: string;
  hubName: string;
  title: string;
  location: string;
  badge: string;
  directionsUrl: string;
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
  emoji,
  hubName,
  title,
  location,
  badge,
  directionsUrl,
}: BoothCardProps) {
  return (
    <div
      className="
        group
        rounded-[28px]
        border border-primary/10 bg-white/70 backdrop-blur-xl
        shadow-sm
        transition-all duration-300
        hover:shadow-[0_20px_50px_rgba(54,78,113,0.18)]
        hover:-translate-y-1 hover:scale-[1.02]
      "
    >
      <div className="p-6 md:p-7">
        {/* Placeholder "image" */}
        <div
          className="
            relative overflow-hidden
            rounded-3xl
            border border-white/50
            bg-linear-to-br from-primary/15 via-white to-yellow-100/40
            transition-transform duration-500
            group-hover:scale-[1.04]
          "
        >
          <div className="aspect-16/10 w-full" />
          <div className="absolute inset-0 bg-white/20 backdrop-blur-sm" />
          <div className="absolute inset-0 flex flex-col items-center justify-center text-center px-4">
            <div className="text-4xl leading-none">{emoji}</div>
            <p className="mt-3 text-sm font-semibold text-gray-900/80">
              {hubName}
            </p>
          </div>
        </div>

        <div className="mt-5 flex items-start justify-between gap-4">
          <div className="min-w-0">
            <p className="flex items-center gap-2 text-sm font-semibold text-primary/80">
              <span aria-hidden className="text-lg">
                {emoji}
              </span>
              <span className="truncate">{hubName}</span>
            </p>
            <h3 className="mt-2 text-xl md:text-2xl font-bold text-gray-900 leading-snug">
              {title}
            </h3>
          </div>

          <span
            className="
              inline-flex items-center justify-center
              rounded-full
              border border-primary/15 bg-primary/5
              px-3 py-1
              text-[11px] font-semibold tracking-[0.16em] text-primary/80
            "
          >
            {badge}
          </span>
        </div>

        <div className="mt-4 flex items-center gap-2 text-gray-700">
          <LocationPinIcon className="w-4 h-4 text-primary" />
          <p className="text-sm text-gray-600">{location}</p>
        </div>

        <div className="mt-6">
          <a
            href={directionsUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="
              inline-flex items-center justify-center
              w-full
              px-6 py-3 rounded-full
              bg-primary text-white font-semibold
              transition-all duration-300
              hover:bg-primary-light
              hover:shadow-[0_16px_40px_rgba(54,78,113,0.25)]
              active:bg-primary-dark
              group-focus-visible:outline-none group-focus-visible:ring-2 group-focus-visible:ring-primary/40
            "
          >
            <span>Get Directions</span>
            <svg
              className="
                w-4 h-4 ml-2
                transition-transform duration-300
                group-hover:translate-x-0.5
              "
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth={2}
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden
            >
              <path d="M5 12h14" />
              <path d="m13 5 7 7-7 7" />
            </svg>
          </a>
        </div>
      </div>
    </div>
  );
}

const OfflineBoothsSection: React.FC = () => {
  return (
    <section
      id="offline"
      className="relative overflow-hidden bg-linear-to-b from-white via-gray-50/70 to-primary/5 py-16 md:py-20 scroll-mt-24"
    >
      {/* Subtle decorative blurs */}
      <div className="absolute -top-16 left-1/2 w-72 h-72 -translate-x-1/2 rounded-full bg-primary/10 blur-3xl" />
      <div className="absolute -bottom-20 -left-10 w-72 h-72 rounded-full bg-pink-200/25 blur-3xl" />
      <div className="absolute -bottom-20 -right-10 w-72 h-72 rounded-full bg-yellow-200/25 blur-3xl" />

      <div className="relative container mx-auto px-4 md:px-6">
        <div className="mx-auto max-w-3xl text-center">
          <h2 className="text-3xl md:text-4xl font-extrabold tracking-tight text-gray-900">
            Find Photomate Offline <span aria-hidden>📍</span>
          </h2>
          <p className="mt-4 text-gray-600 text-base md:text-lg leading-relaxed">
            Not only for events — you can now experience Photomate at our
            permanent locations.
          </p>
        </div>

        <div className="mt-10 md:mt-14 grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8">
          <BoothCard
            emoji="📸"
            hubName="Golio Hub"
            title="Photobox Experience"
            location="Kepanjen, Malang"
            badge="NOW OPEN"
            directionsUrl="https://www.google.com/maps/search/?api=1&query=Golio+Hub%2C+Kepanjen%2C+Malang"
          />
          <BoothCard
            emoji="📰"
            hubName="Janus Coffee"
            title="Newspaper Photobooth"
            location="Kota Batu"
            badge="NOW OPEN"
            directionsUrl="https://www.google.com/maps/search/?api=1&query=Janus+Coffee%2C+Kota+Batu"
          />
        </div>
      </div>
    </section>
  );
};

export default OfflineBoothsSection;

