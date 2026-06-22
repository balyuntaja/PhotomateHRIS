import { Link } from "react-router-dom";
import { blogArticles } from "../data/blogArticles";
import OptimizedImage from "./OptimizedImage";

const blogPreviewItems = blogArticles.slice(0, 3);

const BlogSection: React.FC = () => {
  return (
    <section id="blog" className="py-20 bg-gray-50 scroll-mt-24">
      <div className="container mx-auto px-4 max-w-6xl">
        {/* Header */}
        <div className="text-center mb-12">
          <h2 className="text-3xl md:text-4xl font-bold text-primary">
            Blog
          </h2>
          <span className="block w-20 h-1 bg-primary mx-auto mt-4 rounded-full" />
          <p className="text-gray-600 mt-4 max-w-xl mx-auto">
            Tips, inspirasi, dan panduan seputar photobooth untuk acara Anda.
          </p>
        </div>

        {/* Cards Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
          {blogPreviewItems.map((item) => (
            <Link
              key={item.id}
              to={`/blog/${item.id}`}
              className="group bg-white rounded-2xl shadow-md overflow-hidden hover:shadow-xl transition-all duration-300 hover:-translate-y-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
            >
              <div className="relative aspect-video overflow-hidden bg-gray-100">
                <OptimizedImage
                  src={item.image}
                  alt=""
                  aria-hidden
                  width={800}
                  height={450}
                  className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                />
              </div>
              <div className="p-5 md:p-6">
                <p className="text-xs uppercase tracking-wide text-gray-500 mb-2">
                  {item.date}
                </p>
                <h3 className="text-lg font-bold text-gray-800 mb-2 group-hover:text-primary transition-colors line-clamp-2">
                  {item.title}
                </h3>
                <p className="text-gray-600 text-sm leading-relaxed line-clamp-2 mb-4">
                  {item.shortDescription}
                </p>
                <span className="inline-flex items-center text-primary font-medium text-sm group-hover:gap-2 gap-1 transition-[gap]">
                  Baca artikel
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                  </svg>
                </span>
              </div>
            </Link>
          ))}
        </div>

        {/* CTA */}
        <div className="text-center mt-12">
          <Link
            to="/blog"
            className="inline-flex items-center gap-2 px-6 py-3 bg-primary text-white font-semibold rounded-full hover:bg-primary-dark transition-colors duration-300 shadow-md hover:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          >
            Lihat Semua Artikel
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
            </svg>
          </Link>
        </div>
      </div>
    </section>
  );
};

export default BlogSection;
