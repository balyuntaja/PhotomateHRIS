import { useEffect } from "react";
import { useLocation } from "react-router-dom";

/**
 * Setiap route berubah: scroll ke atas, atau ke elemen dengan id dari hash (e.g. /#pricing).
 */
const ScrollToTop: React.FC = () => {
  const { pathname, hash } = useLocation();

  useEffect(() => {
    if (hash) {
      const id = hash.replace("#", "");
      const el = document.getElementById(id);
      if (el) {
        requestAnimationFrame(() => el.scrollIntoView({ behavior: "smooth", block: "start" }));
        return;
      }
    }
    window.scrollTo(0, 0);
  }, [pathname, hash]);

  return null;
};

export default ScrollToTop;
