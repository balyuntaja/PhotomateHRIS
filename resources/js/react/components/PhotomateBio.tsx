import { FaInstagram, FaWhatsapp, FaTiktok, FaGlobe } from "react-icons/fa";
import backgroundPink from "../assets/img/pink.png";
import logoImg from "../assets/img/logophotomateblue.png";
import putraPutriPertanianImg from "../assets/img/album_photostrip/bio_ppp.png";
import halalBihalalImg from "../assets/img/album_photostrip/halbi_3c.png";
import OptimizedImage from "./OptimizedImage";
import ftspCupImg from "../assets/img/album_photostrip/ftsp_cup.png";
import masLanMbakSmanekaImg from "../assets/img/album_photostrip/mas_lan_mbak.png";
import munas_ftp_ubImg from "../assets/img/album_photostrip/COVER_MUNAS.png";
import bioSmarihastaImg from "../assets/img/album_photostrip/bio_smarihasta.png";
import bioSpaceSmanawaImg from "../assets/img/album_photostrip/cover_space.png";

const photostrips = [
  {
    title: "Photomate x SPACE SMANAWA 2026",
    image: bioSpaceSmanawaImg,
    link: "https://fotoshare.co/e/K2OsVm0YZy_9ZFdAREm2w",
  },
  {
    title: "Photomate x Class Meeting SMARIHASTA 2026",
    image: bioSmarihastaImg,
    link: "https://fotoshare.co/e/C8kDBOvoVz4CsukwOyNtR",
  },
  {
    title: "Photomate x Munas FTP UB 2026",
    image: munas_ftp_ubImg,
    link: "https://fotoshare.co/e/WDquEtye5yVkmrNZGWRne",
  },
  {
    title: "Photomate x Putra Putri Pertanian 2026",
    image: putraPutriPertanianImg,
    link: "https://dreamboothstudio.com/id/gallery/events/69f4ac08e8db801d20b76f35",
  },
  {
    title: "Photomate x Halal Bihalal 3C",
    image: halalBihalalImg,
    link: "https://fotoshare.co/e/rw4_yOFbcg-aCrFaZjAj1",
  },
  {
    title: "Photomate x FTSP Cup",
    image: ftspCupImg,
    link: "https://fotoshare.co/e/enXdC83DHSxdMlI7jWsgb",
  },
  {
    title: "Photomate x Mas Lan Mbak SMANEKA",
    image: masLanMbakSmanekaImg,
    link: "https://fotoshare.co/e/Bbq95XD85zwy6hrqPaxb6",
  },
];

export default function PhotomateBio() {
  const cmsData = (window as any).CMS_DATA || {};
  const dbSettings = cmsData.bio_settings;
  const dbPhotostrips = cmsData.bio_photostrips;

  const hasDbSettings = !!dbSettings;

  const title = hasDbSettings ? (dbSettings.title || "photomate.id") : "photomate.id";
  const logo = (hasDbSettings && dbSettings.logo) ? dbSettings.logo : logoImg;
  const description = hasDbSettings
    ? (dbSettings.description || "")
    : "Capture memories with your mate! 📸 \n Curious? Explore more on our website.";

  const instagramUrl = hasDbSettings ? dbSettings.instagram_url : "https://www.instagram.com/photomateid_/";
  const whatsappUrl = hasDbSettings ? dbSettings.whatsapp_url : "https://wa.me/6287787405280";
  const tiktokUrl = hasDbSettings ? dbSettings.tiktok_url : "https://www.tiktok.com/@photomate_id";
  const websiteUrl = hasDbSettings ? dbSettings.website_url : "https://photomate.id";

  const displayPhotostrips = dbPhotostrips && dbPhotostrips.length > 0
    ? dbPhotostrips
    : photostrips;

  return (
    <div
      className="min-h-screen text-primary px-4 py-10"
      style={{
        backgroundImage: `url(${backgroundPink})`,
        backgroundRepeat: "repeat",
        backgroundPosition: "center",
        backgroundSize: "auto",
      }}
    >
      <div className="mx-auto max-w-3xl">
        {/* Header */}
        <div className="flex items-center gap-4">
          <OptimizedImage
            src={logo}
            className="w-20 h-20 rounded-full"
            alt={`${title} logo`}
            width={80}
            height={80}
          />
          <div className="space-y-1">
            <h1 className="text-3xl font-extrabold tracking-wide">{title}</h1>
            <p className="text-base font-medium leading-relaxed text-primary max-w-md whitespace-pre-line">
              {description}
            </p>

            {/* Social Icons */}
            <div className="flex items-center gap-5 mt-3 text-xl">
              {instagramUrl && (
                <a
                  href={instagramUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="hover:opacity-90"
                  aria-label="Instagram"
                >
                  <FaInstagram />
                </a>
              )}
              {whatsappUrl && (
                <a
                  href={whatsappUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="hover:opacity-90"
                  aria-label="WhatsApp"
                >
                  <FaWhatsapp />
                </a>
              )}
              {tiktokUrl && (
                <a
                  href={tiktokUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="hover:opacity-90"
                  aria-label="TikTok"
                >
                  <FaTiktok />
                </a>
              )}
              {websiteUrl && (
                <a
                  href={websiteUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="hover:opacity-90"
                  aria-label="Website"
                >
                  <FaGlobe />
                </a>
              )}
            </div>
          </div>
        </div>

        {/* Photostrip Section */}
        {displayPhotostrips.length > 0 && (
          <div className="mt-10">
            <h2 className="text-center mb-4 text-lg font-semibold">Link Photostrip</h2>

            <div className="-mx-4 px-4">
              <div className="flex gap-4 overflow-x-auto no-scrollbar">
                {displayPhotostrips.map((item: any) => (
                  <a
                    key={item.id || item.title}
                    href={item.link}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="shrink-0 w-[200px]"
                  >
                    {/* Image (200x200 FIXED) */}
                    <div className="w-[200px] h-[200px] rounded-2xl overflow-hidden bg-[#E6D3B3]">
                      <OptimizedImage
                        src={item.image}
                        className="w-full h-full object-cover"
                        alt={item.title}
                        width={200}
                        height={200}
                      />
                    </div>

                    {/* Title BELOW */}
                    <div className="mt-2 text-base font-semibold text-center text-primary">
                      {item.title}
                    </div>
                  </a>
                ))}
              </div>
            </div>
          </div>
        )}

        {/* Contact Section */}
        <div className="mt-10">
          <h2 className="text-center mb-4 text-lg font-semibold">Contact Us at</h2>

          <div className="flex flex-col gap-3">
            {whatsappUrl && (
              <a
                href={whatsappUrl}
                className="grid grid-cols-[28px_1fr_28px] items-center gap-3 bg-primary text-white px-4 py-4 rounded-xl hover:bg-primary-dark transition"
                target="_blank"
                rel="noopener noreferrer"
              >
                <FaWhatsapp className="text-xl justify-self-center" />
                <span className="text-base font-semibold text-center">WhatsApp</span>
                <span aria-hidden />
              </a>
            )}

            {instagramUrl && (
              <a
                href={instagramUrl}
                className="grid grid-cols-[28px_1fr_28px] items-center gap-3 bg-primary text-white px-4 py-4 rounded-xl hover:bg-primary-dark transition"
                target="_blank"
                rel="noopener noreferrer"
              >
                <FaInstagram className="text-xl justify-self-center" />
                <span className="text-base font-semibold text-center">Instagram</span>
                <span aria-hidden />
              </a>
            )}

            {tiktokUrl && (
              <a
                href={tiktokUrl}
                className="grid grid-cols-[28px_1fr_28px] items-center gap-3 bg-primary text-white px-4 py-4 rounded-xl hover:bg-primary-dark transition"
                target="_blank"
                rel="noopener noreferrer"
              >
                <FaTiktok className="text-xl justify-self-center" />
                <span className="text-base font-semibold text-center">TikTok</span>
                <span aria-hidden />
              </a>
            )}

            {websiteUrl && (
              <a
                href={websiteUrl}
                className="grid grid-cols-[28px_1fr_28px] items-center gap-3 bg-primary text-white px-4 py-4 rounded-xl hover:bg-primary-dark transition"
                target="_blank"
                rel="noopener noreferrer"
              >
                <FaGlobe className="text-xl justify-self-center" />
                <span className="text-base font-semibold text-center">Website</span>
                <span aria-hidden />
              </a>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
