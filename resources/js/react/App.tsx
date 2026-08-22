import React, { lazy, Suspense } from "react";
import { Routes, Route } from "react-router-dom";
import Navbar from "./components/Navbar";
import Hero from "./components/Hero";
import Footer from "./components/Footer";
import BackToTop from "./components/BackToTop";
import WhatsAppFAB from "./components/WhatsAppFAB";
import ChatbotFAB from "./components/ChatbotFAB";
import FindUsSection from "./components/FindUsSection";
import OfflineBoothsSection from "./components/OfflineBoothsSection";
import ScrollToTop from "./components/ScrollToTop";

import Client from "./components/Client";
import Pricing from "./components/Pricing";
import CallToAction from "./components/CallToAction";
import Services from "./components/Services";
import WhySection from "./components/WhySection";
import ClientFeedback from "./components/ClientFeedback";
import Subscribe from "./components/Subscribe";
import Gallery from "./components/Gallery";
import BlogSection from "./components/BlogSection";
import Faq from "./components/Faq";

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
const RecommendationPage = lazy(() => import("./pages/RecommendationPage"));


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
      </main>
      <Footer />
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
          <Route path="/rekomendasi-paket" element={<RecommendationPage />} />
          <Route path="/blog" element={<BlogPage />} />
          <Route path="/blog/:id" element={<BlogDetailPage />} />
          <Route path="/bio" element={<PhotomateBio />} />
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </Suspense>

      {/* Global Floating Action Buttons */}
      <WhatsAppFAB />
      <ChatbotFAB />
      <BackToTop />
    </div>
  );
}

export default App;
