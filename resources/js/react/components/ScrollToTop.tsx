import { useEffect } from "react";
import { useLocation } from "react-router-dom";
import { getArticleById } from "../data/blogArticles";

/**
 * Setiap route berubah: scroll ke atas, atau ke elemen dengan id dari hash (e.g. /#pricing),
 * serta memperbarui document.title secara dinamis untuk SEO client-side.
 */
const ScrollToTop: React.FC = () => {
  const { pathname, hash } = useLocation();

  useEffect(() => {
    // 1. Handle scrolling
    if (hash) {
      const id = hash.replace("#", "");
      const el = document.getElementById(id);
      if (el) {
        requestAnimationFrame(() => el.scrollIntoView({ behavior: "smooth", block: "start" }));
      } else {
        window.scrollTo(0, 0);
      }
    } else {
      window.scrollTo(0, 0);
    }

    // 2. Handle dynamic titles
    let title = "Photomate.id - Jasa Sewa Photobooth Premium Malang & Jawa Timur";
    if (pathname.startsWith("/pricing/sewa")) {
      title = "Paket Sewa Photobooth Premium - Photomate.id";
    } else if (pathname.startsWith("/pricing/self-run")) {
      title = "Paket Mandiri (Self-Run) Photobooth - Photomate.id";
    } else if (pathname.startsWith("/pricing/sharing-profit")) {
      title = "Kemitraan Sharing Profit / Bagi Hasil Photobooth - Photomate.id";
    } else if (pathname.startsWith("/availability")) {
      title = "Cek Jadwal & Ketersediaan Event - Photomate.id";
    } else if (pathname.startsWith("/blog/")) {
      const segments = pathname.split("/");
      const blogId = Number(segments[segments.length - 1]);
      const article = getArticleById(blogId);
      if (article) {
        title = `${article.title} - Blog Photomate.id`;
      } else {
        title = "Artikel Tidak Ditemukan - Blog Photomate.id";
      }
    } else if (pathname === "/blog") {
      title = "Artikel, Tips & Inspirasi Seputar Event & Photobooth - Blog Photomate.id";
    } else if (pathname === "/bio") {
      title = "Photomate.id Link Bio - Kontak, Whatsapp, & Informasi Resmi";
    } else if (pathname !== "/") {
      title = "Photomate.id - Photobooth Premium";
    }

    document.title = title;
  }, [pathname, hash]);

  return null;
};

export default ScrollToTop;
