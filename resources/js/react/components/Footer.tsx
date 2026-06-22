const Footer: React.FC = () => {
  return (
    <footer id="contact" className="bg-gray-950 text-gray-300">
      <div className="container mx-auto px-4 py-14">

        <div className="grid grid-cols-1 md:grid-cols-2 gap-10 items-start">
          
          {/* LEFT — CONTACT */}
          <div className="space-y-5">
            <h2 className="text-white text-lg font-semibold">
              Photomate.id
            </h2>

            <div className="space-y-3 text-sm">
              <p>
                <span className="text-gray-300">Email</span><br />
                <a
                  href="mailto:captureyourmate@gmail.com"
                  className="hover:text-white transition hover:underline underline-offset-4"
                >
                  captureyourmate@gmail.com
                </a>
              </p>

              <p>
                <span className="text-gray-300">WhatsApp</span><br />
                <a
                  href="https://wa.me/6287787405280"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="hover:text-white transition hover:underline underline-offset-4"
                >
                  +62 877-8740-5280 (Iffah)
                </a>
              </p>

              <p>
                <span className="text-gray-300">Instagram</span><br />
                <a
                  href="https://www.instagram.com/photomateid_/"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="hover:text-white transition hover:underline underline-offset-4"
                >
                  @photomateid_
                </a>
              </p>

              <p>
                <span className="text-gray-300">TikTok</span><br />
                <a
                  href="https://www.tiktok.com/@photomate_id"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="hover:text-white transition hover:underline underline-offset-4"
                >
                  @photomate_id
                </a>
              </p>
            </div>
          </div>

          {/* RIGHT — MAP */}
          <div className="overflow-hidden rounded-lg border border-white/10">
            <iframe
              src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3913.5769554962385!2d112.55624367500079!3d-8.144603581565857!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2e789f0001afb5ef%3A0x6a802611716e5742!2sPhotomate.id!5e1!3m2!1sid!2sid!4v1769590547251!5m2!1sid!2sid"
              title="Lokasi Photomate di Google Maps"
              width="100%"
              height="280px"
              style={{ border: 0 }}
              loading="lazy"
              referrerPolicy="no-referrer-when-downgrade"
            />
          </div>

        </div>

        {/* ADDRESS */}
        <div className="mt-12 text-sm text-gray-300 max-w-2xl">
          Jl. Sumber, RT02/04, Tulakan, Panggungrejo,  
          Kec. Kepanjen, Kabupaten Malang,  
          Jawa Timur 65163
        </div>
      </div>

      {/* COPYRIGHT */}
      <div className="border-t border-white/10 py-4">
        <p className="text-center text-xs text-gray-300">
          © {new Date().getFullYear()} Photomate.id. All rights reserved.
        </p>
      </div>
    </footer>
  );
};

export default Footer;
