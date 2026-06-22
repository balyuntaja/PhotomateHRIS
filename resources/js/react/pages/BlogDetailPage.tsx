import { Link, useParams } from "react-router-dom";
import { Footer, Navbar } from "../components";
import OptimizedImage from "../components/OptimizedImage";
import { getArticleById, blogArticles } from "../data/blogArticles";

function getReadingTime(body: string): number {
  const wordsPerMin = 200;
  const words = body.trim().split(/\s+/).length;
  return Math.max(1, Math.ceil(words / wordsPerMin));
}

const BlogDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const article = id ? getArticleById(Number(id)) : undefined;
  const relatedArticles = article
    ? blogArticles.filter((a) => a.id !== article.id).slice(0, 3)
    : [];

  if (!article) {
    return (
      <div className="min-h-screen bg-gray-50">
        <Navbar />
        <main className="container mx-auto px-4 pt-24 pb-20 max-w-2xl text-center">
          <h1 className="text-2xl font-bold text-gray-800 mb-2">Artikel tidak ditemukan</h1>
          <p className="text-gray-600 mb-8">URL mungkin salah atau artikel telah dipindahkan.</p>
          <Link
            to="/blog"
            className="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white font-medium rounded-full hover:bg-primary-dark transition-colors"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
            Lihat Semua Artikel
          </Link>
        </main>
        <Footer />
      </div>
    );
  }

  const readingTime = getReadingTime(article.body);

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <main className="container mx-auto px-4 pt-24 pb-20 max-w-3xl">
        {/* Back */}
        <nav className="mb-8" aria-label="Navigasi">
          <Link
            to="/blog"
            className="inline-flex items-center gap-2 text-gray-600 hover:text-primary font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 rounded"
          >
            <svg className="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
            Semua Artikel
          </Link>
        </nav>

        <article>
          <header className="mb-6">
            <p className="text-sm text-gray-500 mb-2 flex items-center gap-3">
              <span>{article.date}</span>
              <span className="text-gray-300">·</span>
              <span>{readingTime} min baca</span>
            </p>
            <h1 className="text-3xl md:text-4xl font-bold text-gray-800 leading-tight">
              {article.title}
            </h1>
          </header>

          <div className="rounded-2xl overflow-hidden shadow-lg mb-8 bg-gray-100">
            <OptimizedImage
              src={article.image}
              alt={article.title}
              width={1200}
              height={675}
              className="w-full aspect-video object-cover"
            />
          </div>

          <div className="text-gray-700 leading-relaxed space-y-5">
            {article.body.split("\n\n").map((paragraph, i) => (
              <p key={i} className="text-[1.0625rem]">
                {paragraph}
              </p>
            ))}
          </div>
        </article>

        {/* Related */}
        {relatedArticles.length > 0 && (
          <section className="mt-16 pt-12 border-t border-gray-200">
            <h2 className="text-xl font-bold text-gray-800 mb-6">Artikel lainnya</h2>
            <ul className="space-y-4">
              {relatedArticles.map((a) => (
                <li key={a.id}>
                  <Link
                    to={`/blog/${a.id}`}
                    className="group flex gap-4 p-4 rounded-xl hover:bg-white transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                  >
                    <div className="w-24 h-16 shrink-0 rounded-lg overflow-hidden bg-gray-100">
                      <OptimizedImage
                        src={a.image}
                        alt=""
                        width={240}
                        height={160}
                        className="w-full h-full object-cover"
                        aria-hidden
                      />
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="text-xs text-gray-500 mb-0.5">{a.date}</p>
                      <span className="font-semibold text-gray-800 group-hover:text-primary transition-colors line-clamp-2">
                        {a.title}
                      </span>
                    </div>
                    <svg className="w-5 h-5 shrink-0 text-gray-400 group-hover:text-primary group-hover:translate-x-0.5 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                    </svg>
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        )}
      </main>
      <Footer />
    </div>
  );
};

export default BlogDetailPage;
