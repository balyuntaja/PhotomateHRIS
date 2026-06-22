import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import logoBlue from "../assets/img/logophotomateblue.png";
import OptimizedImage from "./OptimizedImage";

const PRICING_SUBLINKS = [
  { label: "Pricelist Sewa", to: "/pricing/sewa" },
  { label: "Paket Self Run", to: "/pricing/self-run" },
  { label: "Sharing Profit", to: "/pricing/sharing-profit" },
];

const MENU_ITEMS = [
  { label: "Home", to: "/", hash: "home" },
  { label: "Clients", to: "/", hash: "clients" },
  { label: "Gallery", to: "/", hash: "gallery" },
  { label: "Cek Jadwal", to: "/availability", hash: null },
  { label: "Blog", to: "/blog", hash: null },
];

const WA_NUMBER = "6287787405280";
const WA_MESSAGE = encodeURIComponent(
  "Hai Photomate 👋 saya tertarik dengan layanan photobooth. Boleh minta info pricelist dan paket yang tersedia?",
);
const WA_URL = `https://wa.me/${WA_NUMBER}?text=${WA_MESSAGE}`;

const Navbar: React.FC = () => {
  const [isScrolled, setIsScrolled] = useState(false);
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const [pricingOpen, setPricingOpen] = useState(false);

  useEffect(() => {
    const onScroll = () => setIsScrolled(window.scrollY > 60);
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    if (isMenuOpen) document.body.style.overflow = "hidden";
    else document.body.style.overflow = "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [isMenuOpen]);

  const closeMenu = () => setIsMenuOpen(false);

  return (
    <nav className="fixed top-4 left-0 right-0 z-50 px-2 md:px-4">
      <div
        className={`
          mx-auto max-w-7xl
          rounded-xl md:rounded-2xl
          transition-all duration-500 ease-out
          ${
            isScrolled
              ? `
                bg-white/70 backdrop-blur-xl
                ring-1 ring-black/5 shadow-2xl
              `
              : "bg-white shadow-lg md:shadow-2xl"
          }
        `}
      >
        <div className="px-4 md:px-6">
          <div className="flex h-16 md:h-20 items-center justify-between">
            {/* LOGO */}
            <Link
              to="/"
              onClick={closeMenu}
              className="flex items-center gap-2"
            >
              <OptimizedImage
                src={logoBlue}
                alt="Photomate"
                width={220}
                height={72}
                className="h-8 md:h-9 w-auto"
              />
              <span
                className={`
                  text-xl md:text-2xl font-extrabold tracking-tight transition-all duration-500 text-primary
                `}
              >
                Photomate.id
              </span>
            </Link>

            {/* Desktop MENU */}
            <div
              className={`
                hidden md:flex items-center gap-3 lg:gap-5 font-medium text-sm lg:text-base transition-colors duration-500 mr-3
                ${isScrolled ? "text-gray-800" : "text-primary"}
              `}
            >
              {MENU_ITEMS.map((item) => (
                <span key={item.label} className="flex items-center gap-0">
                  <Link
                    to={item.hash ? `${item.to}#${item.hash}` : item.to}
                    onClick={closeMenu}
                    className="
                      relative py-2
                      after:absolute after:left-0 after:-bottom-1
                      after:h-[2px] after:w-0 after:bg-current
                      after:transition-all after:duration-300
                      hover:after:w-full
                    "
                  >
                    {item.label}
                  </Link>
                  {item.label === "Clients" && (
                    <>
                      {/* Pricing dropdown */}
              <div
                className="relative ml-4"
                onMouseEnter={() => setPricingOpen(true)}
                onMouseLeave={() => setPricingOpen(false)}
              >
                <button
                  type="button"
                  onClick={() => setPricingOpen((v) => !v)}
                  className="
                    relative py-2 flex items-center gap-0.5
                    after:absolute after:left-0 after:-bottom-1
                    after:h-[2px] after:w-0 after:bg-current
                    after:transition-all after:duration-300
                    hover:after:w-full
                  "
                  aria-expanded={pricingOpen}
                  aria-haspopup="true"
                >
                  Pricing
                  <svg
                    className={`w-4 h-4 transition-transform ${pricingOpen ? "rotate-180" : ""}`}
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
                <div
                  className={`absolute top-full left-0 mt-1 py-2 min-w-[200px] rounded-xl bg-white border border-gray-200 shadow-lg transition-opacity duration-200 ${pricingOpen ? "opacity-100 visible" : "opacity-0 invisible pointer-events-none"}`}
                >
                  {PRICING_SUBLINKS.map((sub) => (
                    <Link
                      key={sub.to}
                      to={sub.to}
                      onClick={() => { closeMenu(); setPricingOpen(false); }}
                      className="block px-4 py-2.5 text-sm hover:bg-primary/10 rounded-lg mx-1"
                    >
                      {sub.label}
                    </Link>
                  ))}
                </div>
              </div>
                    </>
                  )}
                </span>
              ))}
            </div>

            {/* Desktop Contact Us button → WhatsApp */}
            <a
              href={WA_URL}
              target="_blank"
              rel="noopener noreferrer"
              onClick={closeMenu}
              className="
                hidden md:inline-flex items-center justify-center
                px-4 py-2.5 rounded-full text-sm font-semibold
                bg-primary text-white shadow-md shadow-primary/30
                hover:bg-primary/90 active:bg-primary/95
                transition-colors duration-200
              "
            >
              Contact Us
            </a>

            {/* Mobile: Hamburger */}
            <button
              type="button"
              aria-label={isMenuOpen ? "Tutup menu" : "Buka menu"}
              aria-expanded={isMenuOpen}
              onClick={() => setIsMenuOpen((v) => !v)}
              className="
                md:hidden flex flex-col justify-center items-center w-10 h-10 rounded-lg
                text-primary hover:bg-gray-100 active:bg-gray-200 transition
              "
            >
              <span
                className={`block w-6 h-0.5 bg-current rounded-full transition-all duration-300 ${
                  isMenuOpen ? "rotate-45 translate-y-1" : ""
                }`}
              />
              <span
                className={`block w-6 h-0.5 bg-current rounded-full my-1.5 transition-all duration-300 ${
                  isMenuOpen ? "opacity-0 scale-0" : ""
                }`}
              />
              <span
                className={`block w-6 h-0.5 bg-current rounded-full transition-all duration-300 ${
                  isMenuOpen ? "-rotate-45 -translate-y-1" : ""
                }`}
              />
            </button>
          </div>
        </div>

        {/* Mobile menu overlay + panel */}
        <div
          className={`
            md:hidden fixed inset-0 top-20 z-40
            bg-black/40 backdrop-blur-sm
            transition-opacity duration-300
            ${isMenuOpen ? "opacity-100" : "opacity-0 pointer-events-none"}
          `}
          onClick={closeMenu}
          aria-hidden="true"
        />
        <div
          className={`
            md:hidden absolute left-0 right-0 top-full z-50 mt-0
            rounded-b-xl md:rounded-b-2xl overflow-hidden
            bg-white border-t border-gray-100
            shadow-2xl
            transition-[transform,opacity] duration-300 ease-out
            ${isMenuOpen ? "translate-y-0 opacity-100" : "-translate-y-4 opacity-0 pointer-events-none"}
          `}
        >
          <div className="py-2 px-2">
            {MENU_ITEMS.map((item) => (
              <Link
                key={item.label}
                to={item.hash ? `${item.to}#${item.hash}` : item.to}
                onClick={closeMenu}
                className="flex py-3 px-4 rounded-xl text-primary font-medium hover:bg-primary/10 active:bg-primary/15 transition-colors"
              >
                {item.label}
              </Link>
            ))}
            {/* Mobile Pricing submenu */}
            <div className="border-t border-gray-100 mt-1 pt-1">
              <button
                type="button"
                onClick={() => setPricingOpen((v) => !v)}
                className="w-full flex items-center justify-between py-3 px-4 rounded-xl text-primary font-medium hover:bg-primary/10 active:bg-primary/15 transition-colors"
                aria-expanded={pricingOpen}
              >
                Pricing
                <svg
                  className={`w-4 h-4 transition-transform ${pricingOpen ? "rotate-180" : ""}`}
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
              </button>
              <div className={`overflow-hidden transition-all duration-200 ${pricingOpen ? "max-h-44" : "max-h-0"}`}>
                {PRICING_SUBLINKS.map((sub) => (
                  <Link
                    key={sub.to}
                    to={sub.to}
                    onClick={() => { closeMenu(); setPricingOpen(false); }}
                    className="flex py-2.5 pl-6 pr-4 rounded-xl text-primary/90 text-sm font-medium hover:bg-primary/10 active:bg-primary/15"
                  >
                    {sub.label}
                  </Link>
                ))}
              </div>
            </div>

            {/* Mobile Contact Us button → WhatsApp */}
            <a
              href={WA_URL}
              target="_blank"
              rel="noopener noreferrer"
              onClick={closeMenu}
              className="mt-3 mb-1 flex justify-center py-3 px-4 rounded-xl text-sm font-semibold bg-primary text-white shadow-md shadow-primary/30 hover:bg-primary/90 active:bg-primary/95 transition-colors"
            >
              Contact Us
            </a>
          </div>
        </div>
      </div>
    </nav>
  );
};

export default Navbar;
