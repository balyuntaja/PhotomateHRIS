import { lazy, Suspense } from "react";
import { Routes, Route } from "react-router-dom";
import {
  Navbar,
  Hero,
  Footer,
  BackToTop,
  WhatsAppFAB,
  FindUsSection,
  OfflineBoothsSection,
  ScrollToTop,
} from "./components";

const Client = lazy(() => import("./components/Client"));
const Pricing = lazy(() => import("./components/Pricing"));
const CallToAction = lazy(() => import("./components/CallToAction"));
const Services = lazy(() => import("./components/Services"));
const WhySection = lazy(() => import("./components/WhySection"));
const ClientFeedback = lazy(() => import("./components/ClientFeedback"));
const Subscribe = lazy(() => import("./components/Subscribe"));
const Gallery = lazy(() => import("./components/Gallery"));
const BlogSection = lazy(() => import("./components/BlogSection"));
const Faq = lazy(() => import("./components/Faq"));
const BlogPage = lazy(() => import("./pages/BlogPage"));
const BlogDetailPage = lazy(() => import("./pages/BlogDetailPage"));
const EventAvailability = lazy(() => import("./pages/EventAvailability"));
const PricingSewaPage = lazy(() => import("./pages/PricingSewaPage"));
const PricingSelfRunPage = lazy(() => import("./pages/PricingSelfRunPage"));
const PricingSharingProfitPage = lazy(
  () => import("./pages/PricingSharingProfitPage")
);
const NotFoundPage = lazy(() => import("./pages/NotFoundPage"));
const PhotomateBio = lazy(() => import("./components/PhotomateBio"));

function SectionSkeleton() {
  return <div className="h-24" aria-hidden />;
}

function HomePage() {
  return (
    <>
      <Navbar />
      <main id="main-content">
        <Hero />
        <FindUsSection />
        <OfflineBoothsSection />
        <Suspense fallback={<SectionSkeleton />}>
          <Client />
          <Pricing />
          <CallToAction />
          <Services />
          <WhySection />
          <ClientFeedback />
          <Subscribe />
          <Gallery />
          <BlogSection />
          <Faq />
        </Suspense>
      </main>
      <Footer />
      <WhatsAppFAB />
      <BackToTop />
    </>
  );
}

function App() {
  return (
    <div className="min-h-screen overflow-x-hidden">
      <ScrollToTop />
      <Suspense fallback={<SectionSkeleton />}>
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/pricing/sewa" element={<PricingSewaPage />} />
          <Route path="/pricing/self-run" element={<PricingSelfRunPage />} />
          <Route
            path="/pricing/sharing-profit"
            element={<PricingSharingProfitPage />}
          />
          <Route path="/availability" element={<EventAvailability />} />
          <Route path="/blog" element={<BlogPage />} />
          <Route path="/blog/:id" element={<BlogDetailPage />} />
          <Route path="/bio" element={<PhotomateBio />} />
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </Suspense>
    </div>
  );
}

export default App;
