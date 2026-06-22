import { Link } from "react-router-dom";
import { Footer, Navbar } from "../components";
import OptimizedImage from "../components/OptimizedImage";
import { blogArticles } from "../data/blogArticles";

const BlogPage: React.FC = () => {
  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <main className="container mx-auto px-4 pt-24 pb-20 max-w-6xl">
        {/* Breadcrumb / Back */}
        <nav className="mb-8" aria-label="Breadcrumb">
          <Link
            to="/"
            className="inline-flex items-center gap-2 text-gray-600 hover:text-primary font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 rounded"
          >
            <svg
              className="w-5 h-5 shrink-0"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M15 19l-7-7 7-7"
              />
            </svg>
            Beranda
          </Link>
        </nav>

        {/* Header */}
        <header className="mb-12">
          <h1 className="text-3xl md:text-4xl font-bold text-primary">Blog</h1>
          <span className="block w-20 h-1 bg-primary mt-4 rounded-full" />
          <p className="text-gray-600 mt-4 max-w-2xl">
            Artikel, tips, dan inspirasi seputar photobooth untuk wedding,
            corporate event, dan acara spesial Anda.
          </p>
        </header>

        {/* Article List */}
        {blogArticles.length === 0 ? (
          <div className="bg-white rounded-2xl shadow-md p-12 text-center">
            <p className="text-gray-600 mb-4">
              Belum ada artikel untuk saat ini.
            </p>
            <Link
              to="/"
              className="inline-flex items-center gap-2 text-primary font-medium hover:text-primary-dark"
            >
              Kembali ke Beranda
            </Link>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            {blogArticles.map((article) => (
              <Link
                key={article.id}
                to={`/blog/${article.id}`}
                className="group bg-white rounded-2xl shadow-md overflow-hidden hover:shadow-xl transition-all duration-300 hover:-translate-y-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
              >
                <div className="relative aspect-video overflow-hidden bg-gray-100">
                  <OptimizedImage
                    src={article.image}
                    alt=""
                    aria-hidden
                    width={800}
                    height={450}
                    className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                  />
                </div>
                <div className="p-5 md:p-6">
                  <p className="text-xs uppercase tracking-wide text-gray-500 mb-2">
                    {article.date}
                  </p>
                  <h2 className="text-lg font-bold text-gray-800 mb-2 group-hover:text-primary transition-colors line-clamp-2">
                    {article.title}
                  </h2>
                  <p className="text-gray-600 text-sm leading-relaxed line-clamp-2 mb-4">
                    {article.shortDescription}
                  </p>
                  <span className="inline-flex items-center text-primary font-medium text-sm group-hover:gap-2 gap-1 transition-[gap]">
                    Baca selengkapnya
                    <svg
                      className="w-4 h-4"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={2}
                        d="M9 5l7 7-7 7"
                      />
                    </svg>
                  </span>
                </div>
              </Link>
            ))}
          </div>
        )}
      </main>
      <Footer />
    </div>
  );
};

export default BlogPage;
