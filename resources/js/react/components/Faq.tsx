import { useId, useLayoutEffect, useRef, useState } from "react";

const FAQ_LIMIT = 5;

const FaqItem = ({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) => {
  const [open, setOpen] = useState(false);
  const panelId = useId();

  return (
    <div
      data-faq-card
      className={`
        h-fit overflow-hidden rounded-xl border bg-white
        transition-all duration-300 ease-in-out
        ${
          open
            ? "border-primary/25 shadow-[0_12px_32px_-16px_rgba(53,75,109,0.35)]"
            : "border-primary/10 hover:border-primary/25 hover:shadow-[0_10px_28px_-18px_rgba(53,75,109,0.3)]"
        }
      `}
    >
      <button
        type="button"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        aria-controls={panelId}
        className="
          flex w-full items-center justify-between gap-4
          px-4 py-4 text-left sm:px-5
          transition-colors duration-300
          focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary/30
          active:bg-primary/[0.03]
        "
      >
        <span className="text-[15px] font-semibold leading-snug text-primary sm:text-base">
          {title}
        </span>
        <span
          className={`
            flex h-7 w-7 shrink-0 items-center justify-center rounded-full
            bg-primary/5 text-primary transition-all duration-300
            ${open ? "rotate-180 bg-primary/10" : ""}
          `}
        >
          <svg
            className="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth={2}
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="m5 15 7-7 7 7" />
          </svg>
        </span>
      </button>

      <div
        id={panelId}
        inert={!open}
        className={`
          grid transition-all duration-300 ease-in-out
          ${open ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0"}
        `}
      >
        <div className="overflow-hidden">
          <div className="border-t border-primary/10 px-4 pt-3 text-sm leading-relaxed text-gray-600 break-words sm:px-5">
            {children}
          </div>
        </div>
      </div>
    </div>
  );
};

type FaqEntry = {
  id: string | number;
  question: string;
  answer: React.ReactNode;
};

const fallbackFaqs: FaqEntry[] = [
  {
    id: "photomate",
    question: "Apa itu Photomate?",
    answer: (
      <>
        <p className="mb-2 ">
          Photomate adalah layanan photobox profesional untuk berbagai acara
          seperti wedding, corporate event, dan acara spesial.
        </p>
        <p className="pb-5">
          Kami menyediakan cetak foto instan dengan kualitas premium dan
          desain eksklusif.
        </p>
      </>
    ),
  },
  {
    id: "multi-day-booking",
    question: "Apakah bisa booking lebih dari 1 hari?",
    answer: (
      <p className="pb-5">
        Bisa. Untuk pemesanan lebih dari satu hari, silakan hubungi tim kami
        untuk mendapatkan penawaran terbaik.
      </p>
    ),
  },
  {
    id: "event-types",
    question: "Acara apa saja yang bisa dilayani?",
    answer: (
      <ul className="list-disc ps-5 space-y-1 pb-5">
        <li>Wedding</li>
        <li>Corporate Event</li>
        <li>Birthday Party</li>
        <li>Product Launch</li>
        <li>Event Komunitas</li>
      </ul>
    ),
  },
];

const Faq: React.FC = () => {
  const [expanded, setExpanded] = useState(false);
  const [collapsedHeight, setCollapsedHeight] = useState<number | null>(null);
  const [fullHeight, setFullHeight] = useState<number | null>(null);
  const listRef = useRef<HTMLDivElement>(null);

  const cmsFaqs = (window as any).CMS_DATA?.faqs ?? [];
  const faqs: FaqEntry[] =
    cmsFaqs.length > 0
      ? cmsFaqs.map((faq: any) => ({
          id: faq.id,
          question: faq.question,
          answer: (
            <div
              className="whitespace-pre-line pb-5"
              dangerouslySetInnerHTML={{ __html: faq.answer }}
            />
          ),
        }))
      : fallbackFaqs;

  const hasMore = faqs.length > FAQ_LIMIT;

  useLayoutEffect(() => {
    const list = listRef.current;
    if (!list) return;

    const measure = () => {
      const lastVisible = list.querySelectorAll<HTMLElement>(
        "[data-faq-card]"
      )[FAQ_LIMIT - 1];

      if (!lastVisible) return;

      const listTop = list.getBoundingClientRect().top;
      setCollapsedHeight(
        Math.ceil(lastVisible.getBoundingClientRect().bottom - listTop)
      );
      setFullHeight(Math.ceil(list.scrollHeight));
    };

    measure();

    const observer = new ResizeObserver(measure);
    list
      .querySelectorAll<HTMLElement>("[data-faq-card]")
      .forEach((card) => observer.observe(card));

    return () => observer.disconnect();
  }, []);

  return (
    <section id="faq" className="bg-white py-16 md:py-20 scroll-mt-24">
      <div className="max-w-5xl mx-auto px-4 sm:px-6">
        {/* Heading */}
        <div className="text-center mb-10 md:mb-12">
          <h2 className="text-3xl md:text-4xl font-bold text-primary mb-3">
            Frequently Asked Questions
          </h2>
          <p className="text-gray-600">
            Pertanyaan yang sering ditanyakan seputar layanan Photomate
          </p>
        </div>

        <div
          id="faq-list"
          ref={listRef}
          style={
            hasMore && collapsedHeight !== null
              ? {
                  maxHeight: expanded
                    ? fullHeight ?? undefined
                    : collapsedHeight,
                }
              : undefined
          }
          className="
            grid grid-cols-1 items-start gap-3 overflow-hidden
            transition-[max-height] duration-500 ease-in-out
            motion-reduce:transition-none
            sm:gap-4 md:grid-cols-2
          "
        >
          {faqs.map((faq, index) => {
            const isExtra = index >= FAQ_LIMIT;

            return (
              <div
                key={faq.id}
                inert={isExtra && !expanded}
                className={
                  isExtra
                    ? `transition-opacity duration-500 ease-in-out motion-reduce:transition-none ${
                        expanded
                          ? "opacity-100"
                          : "pointer-events-none opacity-0"
                      }`
                    : undefined
                }
              >
                <FaqItem title={faq.question}>{faq.answer}</FaqItem>
              </div>
            );
          })}
        </div>

        {hasMore && (
          <div className="mt-6 flex justify-center">
            <button
              type="button"
              onClick={() => setExpanded((prev) => !prev)}
              aria-expanded={expanded}
              aria-controls="faq-list"
              className="
                inline-flex items-center gap-2 rounded-full
                border border-primary/15 bg-white px-6 py-3
                text-sm font-semibold text-primary
                shadow-sm transition-all duration-300
                hover:border-primary/30 hover:bg-primary/5 hover:shadow-md
                active:scale-[0.98]
                focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30
              "
            >
              {expanded
                ? "Tampilkan Lebih Sedikit"
                : `Lihat Semua FAQ (${faqs.length})`}
              <svg
                className={`h-4 w-4 transition-transform duration-300 ${
                  expanded ? "rotate-180" : ""
                }`}
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={2}
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="m5 15 7-7 7 7"
                />
              </svg>
            </button>
          </div>
        )}
      </div>
    </section>
  );
};

export default Faq;
