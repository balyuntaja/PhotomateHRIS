import { useState } from "react";

const FaqItem = ({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) => {
  const [open, setOpen] = useState(false);

  return (
    <div className="border border-gray-200 rounded-lg overflow-hidden">
      <button
        onClick={() => setOpen(!open)}
        className="
          flex w-full items-center justify-between
          p-5 font-medium text-gray-800
          hover:bg-gray-50 transition
        "
      >
        <span>{title}</span>
        <svg
          className={`w-5 h-5 transition-transform duration-300 ${
            open ? "rotate-180" : ""
          }`}
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          strokeWidth={2}
        >
          <path strokeLinecap="round" strokeLinejoin="round" d="m5 15 7-7 7 7" />
        </svg>
      </button>

      <div
        className={`
          grid transition-all duration-300 ease-in-out
          ${open ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0"}
        `}
      >
        <div className="overflow-hidden px-5  text-gray-600">
          {children}
        </div>
      </div>
    </div>
  );
};

const Faq: React.FC = () => {
  return (
    <section id="faq" className="bg-white py-20 scroll-mt-24">
      <div className="max-w-3xl mx-auto px-6">
        {/* Heading */}
        <div className="text-center mb-12">
          <h2 className="text-3xl md:text-4xl font-bold text-primary mb-3">
            Frequently Asked Questions
          </h2>
          <p className="text-gray-600">
            Pertanyaan yang sering ditanyakan seputar layanan Photomate
          </p>
        </div>

        <div className="space-y-4">
          {((window as any).CMS_DATA?.faqs?.length > 0) ? (
            (window as any).CMS_DATA.faqs.map((faq: any) => (
              <FaqItem key={faq.id} title={faq.question}>
                <div className="pb-5" dangerouslySetInnerHTML={{ __html: faq.answer }} />
              </FaqItem>
            ))
          ) : (
            <>
              <FaqItem title="Apa itu Photomate?">
                <p className="mb-2 ">
                  Photomate adalah layanan photobox profesional untuk berbagai acara
                  seperti wedding, corporate event, dan acara spesial.
                </p>
                <p className="pb-5">
                  Kami menyediakan cetak foto instan dengan kualitas premium dan
                  desain eksklusif.
                </p>
              </FaqItem>

              <FaqItem title="Apakah bisa booking lebih dari 1 hari?">
              <p className="pb-5">
                  Bisa. Untuk pemesanan lebih dari satu hari, silakan hubungi tim kami
                  untuk mendapatkan penawaran terbaik.
                </p>
              </FaqItem>

              <FaqItem title="Acara apa saja yang bisa dilayani?">
                <ul className="list-disc ps-5 space-y-1 pb-5">
                  <li>Wedding</li>
                  <li>Corporate Event</li>
                  <li>Birthday Party</li>
                  <li>Product Launch</li>
                  <li>Event Komunitas</li>
                </ul>
              </FaqItem>
            </>
          )}
        </div>
      </div>
    </section>
  );
};

export default Faq;
