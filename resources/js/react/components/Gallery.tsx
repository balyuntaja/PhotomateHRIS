import { useEffect, useRef, useState } from "react";
import OptimizedImage from "./OptimizedImage";

type Category = "All" | "Wedding" | "Event" | "Brand" | "High School Collaboration";
type ItemCategory = "Wedding" | "Event" | "Brand" | "High School Collaboration";

interface GalleryItem {
  id: number;
  category: ItemCategory;
  image: string;
  alt: string;
}

const categories: Category[] = ["All", "Wedding", "Event", "Brand", "High School Collaboration"];

// Muat gambar dari src/assets/img/event (termasuk wedding*, event*, brand1, brand2, dll)
const eventModules = import.meta.glob<{ default: string }>(
  "../assets/img/event/*.{webp,Webp,jpg,jpeg,png}"
);

const mapModulesToItems = (
  modules: Record<string, { default: string }>
): GalleryItem[] =>
  Object.entries(modules).map(([path, mod], index) => {
    const url = mod.default;
    const filename = path.split("/").pop()?.toLowerCase() ?? "";
    const category: ItemCategory = filename.includes("brand")
      ? "Brand"
      : filename.includes("highschool")
        ? "High School Collaboration"
      : filename.includes("wedding")
        ? "Wedding"
        : "Event";
    const alt =
      category === "Brand"
        ? `Gallery brand Photomate ${index + 1}`
        : category === "High School Collaboration"
          ? `Gallery high school collaboration Photomate ${index + 1}`
        : category === "Wedding"
          ? `Gallery wedding Photomate ${index + 1}`
          : `Gallery event Photomate ${index + 1}`;
    return { id: 0, category, image: url, alt };
  });

const Gallery: React.FC = () => {
  const [activeCategory, setActiveCategory] = useState<Category>("All");
  const [galleryItems, setGalleryItems] = useState<GalleryItem[]>([]);
  const [isGalleryActive, setIsGalleryActive] = useState(false);
  const [visibleCount, setVisibleCount] = useState(6);
  const sectionRef = useRef<HTMLElement>(null);
  const sliderRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const target = sectionRef.current;
    if (!target) return;

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry.isIntersecting) return;
        setIsGalleryActive(true);
        observer.disconnect();
      },
      { rootMargin: "300px" }
    );
    observer.observe(target);
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    if (!isGalleryActive) return;

    const loadGallery = async () => {
      const modulesEntries = await Promise.all(
        Object.entries(eventModules).map(async ([path, importer]) => {
          const mod = await importer();
          return [path, mod] as const;
        })
      );
      const items = mapModulesToItems(
        Object.fromEntries(modulesEntries) as Record<string, { default: string }>
      ).map((item, index) => ({ ...item, id: index + 1 }));
      
      const cmsGalleries = (window as any).CMS_DATA?.galleries?.map((g: any, i: number) => ({
        id: 1000 + i,
        category: (g.category || "Event") as ItemCategory,
        image: g.image,
        alt: g.title || "Gallery Photomate"
      })) || [];
      
      setGalleryItems([...cmsGalleries, ...items]);
    };

    void loadGallery();
  }, [isGalleryActive]);

  const filteredItems =
    activeCategory === "All"
      ? galleryItems
      : galleryItems.filter((item) => item.category === activeCategory);

  const visibleItems = filteredItems.slice(0, visibleCount);

  const scroll = (direction: "left" | "right") => {
    if (!sliderRef.current) return;

    const scrollAmount = sliderRef.current.offsetWidth;
    sliderRef.current.scrollBy({
      left: direction === "left" ? -scrollAmount : scrollAmount,
      behavior: "smooth",
    });
  };

  return (
    <section
      ref={sectionRef}
      id="gallery"
      className="py-20 bg-white scroll-mt-24"
    >
      <div className="container mx-auto px-4">
        {/* Title */}
        <h2 className="text-3xl md:text-4xl font-bold text-primary text-center mb-10">
          Gallery Photomate
          <span className="block w-20 h-1 bg-primary mx-auto mt-4 rounded-full" />
        </h2>

        {/* Filter: All, Wedding, Event, Brand */}
        <div className="flex justify-center flex-wrap gap-3 mb-10">
          {categories.map((category) => (
            <button
              key={category}
              onClick={() => setActiveCategory(category)}
              className={`px-5 py-2.5 rounded-full text-sm md:text-base font-medium border transition
                ${
                  activeCategory === category
                    ? 'bg-primary text-white border-primary'
                    : 'bg-white text-gray-700 border-gray-300 hover:border-primary hover:text-primary'
                }`}
            >
              {category}
            </button>
          ))}
        </div>

        {/* Carousel */}
        <div className="relative">
          <button
            onClick={() => scroll("left")}
            className="absolute left-0 top-1/2 -translate-y-1/2 z-10 bg-white/50 shadow-lg rounded-full py-5 px-2 mx-2 hover:bg-gray-100"
          >
            ‹
          </button>

          <div
            ref={sliderRef}
            className="
              flex gap-4 overflow-x-auto scroll-smooth
              snap-x snap-mandatory
              scrollbar-hide
            "
          >
            {visibleItems.map((item) => (
              <div
                key={item.id}
                className="min-w-[180px] w-[280px] sm:min-w-[300px] sm:w-[300px] shrink-0 snap-center"
              >
                <OptimizedImage
                  src={item.image}
                  alt={item.alt}
                  width={900}
                  height={1350}
                  className="w-full h-auto object-contain rounded-xl transition-transform duration-300 hover:scale-105 aspect-2/3 bg-gray-100"
                />
              </div>
            ))}
          </div>

          <button
            onClick={() => scroll("right")}
            className="absolute right-0 top-1/2 -translate-y-1/2 z-10 bg-white/50 shadow-lg rounded-full py-5 px-2 mx-2 hover:bg-gray-100"
          >
            ›
          </button>
        </div>
        {filteredItems.length > visibleCount && (
          <div className="mt-8 flex justify-center">
            <button
              onClick={() => setVisibleCount((prev) => prev + 6)}
              className="px-6 py-3 rounded-full border border-primary text-primary font-semibold hover:bg-primary hover:text-white transition"
            >
              Muat lebih banyak
            </button>
          </div>
        )}
      </div>
    </section>
  );
};

export default Gallery;
