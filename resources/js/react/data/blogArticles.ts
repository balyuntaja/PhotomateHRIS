import imageBlog1 from "../assets/img/blog/wedding1.jpeg";
import imageBlog2 from "../assets/img/blog/oronamin.jpeg";
import imageBlog3 from "../assets/img/blog/kantor.jpeg";
import imageBlog4 from "../assets/img/blog/birthday.jpeg";
import imageBlog5 from "../assets/img/blog/hasilphotobooth.jpeg";
import imageBlog6 from "../assets/img/blog/community.jpeg";
import imageBlog7 from "../assets/img/achievement.webp";

export interface BlogArticle {
  id: number;
  title: string;
  shortDescription: string;
  date: string;
  image: string;
  body: string;
}

const defaultArticles: BlogArticle[] = [
  {
    id: 1,
    title: "Tips Memilih Photobox untuk Wedding",
    shortDescription:
      "Panduan singkat memilih layanan photobox yang tepat untuk hari istimewa Anda.",
    date: "15 Januari 2026",
    image: imageBlog1,
    body: `Memilih layanan photobox untuk wedding adalah keputusan penting yang bisa membuat hari pernikahan Anda lebih berkesan. Berikut beberapa tips yang bisa Anda pertimbangkan.

Pertama, pastikan vendor memiliki portofolio yang relevan dengan tema pernikahan Anda. Lihat hasil cetak, kualitas gambar, dan desain frame yang ditawarkan. Kedua, cek kelengkapan paket: berapa jam layanan, jumlah cetak, apakah ada backdrop dan props, serta apakah ada asisten di lokasi.

Ketiga, diskusikan kebutuhan khusus seperti logo atau monogram di frame. Keempat, bandingkan harga dengan kualitas dan testimoni dari pengguna sebelumnya. Terakhir, booking jauh-jauh hari karena vendor photobox wedding yang bagus biasanya penuh di musim high season.

Dengan persiapan yang tepat, photobox akan menjadi salah satu momen yang paling dinanti tamu Anda.`,
  },
  {
    id: 2,
    title: "Brand Activation Oronamin C Bersama Photomate di Kampus",
    shortDescription:
      "Aktivasi brand Oronamin C di berbagai kampus melalui photobox interaktif untuk meningkatkan engagement mahasiswa.",
    date: "10 Januari 2026",
    image: imageBlog2,
    body: `Brand activation di lingkungan kampus membutuhkan pendekatan yang kreatif, interaktif, dan relevan dengan gaya hidup mahasiswa. Photomate dipercaya untuk mendukung kegiatan brand activation Oronamin C di beberapa kampus sebagai bagian dari strategi engagement yang fun dan memorable.

Konsep utama aktivasi ini adalah menghadirkan photobox sebagai titik interaksi utama (engagement point). Mahasiswa tidak hanya menerima produk, tetapi juga diajak berpartisipasi aktif melalui sesi foto bersama teman-teman mereka. Dengan pendekatan ini, brand Oronamin C hadir sebagai bagian dari momen seru di kampus, bukan sekadar promosi satu arah.

Photobox dirancang dengan visual yang kuat dan konsisten dengan identitas Oronamin C. Mulai dari backdrop, frame foto, hingga properti foto disesuaikan dengan warna dan pesan brand. Hal ini memastikan setiap hasil cetak foto dan konten digital yang dibagikan mahasiswa tetap membawa identitas Oronamin C secara natural.

Selama acara berlangsung, antusiasme mahasiswa sangat tinggi. Antrian photobox menjadi bukti bahwa aktivitas visual interaktif lebih efektif menarik perhatian dibandingkan booth konvensional. Mahasiswa cenderung mengunggah hasil foto ke media sosial mereka, sehingga exposure brand meluas secara organik ke luar area kampus.

Selain hasil cetak instan, Photomate juga menyediakan online album dan sharing station yang memudahkan peserta mengunduh foto dalam format digital. Beberapa aktivitas juga dilengkapi dengan QR code yang mengarahkan ke campaign page atau akun media sosial brand untuk meningkatkan traffic dan awareness.

Dari sisi brand, photobox menjadi media yang efektif untuk menciptakan pengalaman positif dan berkesan. Brand Oronamin C tidak hanya dikenal, tetapi juga diasosiasikan dengan energi, kebersamaan, dan keseruan aktivitas kampus. Bagi mahasiswa, pengalaman ini meninggalkan memori yang tangible melalui foto cetak yang bisa dibawa pulang.

Melalui kolaborasi ini, Photomate membuktikan bahwa photobox bukan hanya hiburan, tetapi juga bagian dari strategi brand activation yang mampu meningkatkan engagement, memperkuat identitas brand, dan menciptakan pengalaman yang relevan dengan target audiens kampus.`,
  },
  {
    id: 3,
    title: "Kenapa Photobox Wajib di Corporate Event",
    shortDescription:
      "Manfaat photobox untuk engagement dan branding di acara perusahaan.",
    date: "5 Januari 2025",
    image: imageBlog3,
    body: `Photobox bukan sekadar hiburan di corporate event. Ada nilai strategis yang membuat investasi ini sangat berharga.

Dari sisi engagement, tamu dan karyawan jadi lebih interaktif dan suasana acara lebih cair. Ice breaking alami terjadi saat orang antre dan berfoto bersama. Dari sisi branding, frame dan backdrop bisa memuat logo, tagline, atau kampanye perusahaan sehingga setiap foto yang dibawa pulang menjadi media promosi.

Hasil cetak instan juga bisa dibubuhi QR code ke landing page, form feedback, atau sosial media perusahaan. Data peserta yang mengisi form setelah scan QR bisa dipakai untuk follow-up. Untuk event internal seperti gathering atau anniversary, photobox meningkatkan sense of belonging dan konten untuk sosial media perusahaan.

Jadi, anggap photobox sebagai bagian dari strategi event marketing, bukan sekadar dekorasi.`,
  },
  {
    id: 4,
    title: "Ide Konsep Photobox Birthday Party",
    shortDescription:
      "Berbagai tema dan konsep photobox yang cocok untuk pesta ulang tahun.",
    date: "28 Desember 2024",
    image: imageBlog4,
    body: `Pesta ulang tahun akan lebih seru dengan photobox yang tematik. Berikut beberapa ide konsep yang bisa Anda pakai.

Untuk anak-anak: tema karakter favorit, unicorn, atau outer space. Backdrop warna-warni dan props seperti topi ulang tahun dan kacamata lucu. Untuk remaja: tema aesthetic, Y2K, atau neon dengan lighting yang Instagrammable. Untuk dewasa: tema elegan dengan backdrop monokrom atau tema decade (80s, 90s) dengan props yang nostalgic.

Anda bisa custom frame dengan nama dan usia, atau tambahkan hashtag acara agar tamu bisa share dengan tag yang sama. Pilih vendor yang menyediakan props sesuai tema atau yang bisa custom backdrop. Dengan konsep yang jelas, photobox birthday akan jadi spot favorit tamu dan kenangan yang tetap terbawa pulang.`,
  },
  {
    id: 5,
    title: "Cara Merawat Hasil Cetak Foto Instan",
    shortDescription: "Tips menyimpan dan merawat foto cetak agar tahan lama.",
    date: "20 Desember 2024",
    image: imageBlog5,
    body: `Foto cetak instan dari photobox bisa tahan lama jika dirawat dengan benar. Berikut tips singkatnya.

Hindari paparan sinar matahari langsung dan suhu ekstrem. Simpan di tempat kering dan sejuk. Jangan menumpuk foto dalam jumlah banyak tanpa pelindung karena bisa lengket. Gunakan album dengan lapisan anti-UV atau map kertas acid-free untuk arsip jangka panjang.

Jangan menulis dengan pulpen biasa di bagian belakang foto; gunakan pensil atau pen khusus foto. Jika ingin scan atau fotokopi, gunakan pemindai flatbed dan hindari menekan permukaan foto. Untuk frame, gunakan kaca anti-UV dan pastikan foto tidak langsung menempel ke kaca.

Dengan perawatan sederhana ini, kenangan dari photobox Anda bisa awet bertahun-tahun.`,
  },
  {
    id: 6,
    title: "Photobox untuk Acara Komunitas",
    shortDescription:
      "Pengalaman menggunakan photobox di gathering dan meetup komunitas.",
    date: "12 Desember 2024",
    image: imageBlog6,
    body: `Photobox di acara komunitas bisa memperkuat ikatan anggota dan meninggalkan kesan positif. Berikut pengalaman dan tipsnya.

Di gathering atau meetup, photobox berfungsi sebagai ice breaker alami. Anggota yang baru kenal jadi punya momen berfoto bersama dan bahan obrolan. Backdrop bisa memuat nama komunitas dan logo, sehingga setiap foto yang dibagikan di media sosial turut mempromosikan komunitas.

Untuk komunitas dengan anggota tersebar, cetak instan yang dibawa pulang jadi kenangan fisik yang memorable. Anda bisa pakai paket photobox dengan durasi 2–3 jam untuk acara setengah hari. Siapkan juga props yang relevan dengan tema komunitas (misalnya atribut olahraga, hobi, atau profesi).

Hasilnya: engagement tinggi, dokumentasi rapi, dan citra komunitas yang lebih profesional.`,
  },
  {
    id: 7,
    title: "Photomate.id Diberitakan di Times Indonesia & Pencapaian Awal",
    shortDescription:
      "Usaha photobooth express karya Gen Z yang fleksibel dan kreatif, plus debut di LokaNesia 2025, kolaborasi SMA Negeri 1 Batu, dan Road to Malang Walking Race 2025.",
    date: "8 Januari 2026",
    image: imageBlog7,
    body: `Photomate.id mendapat sorotan dari Times Indonesia dalam artikel "Usaha Photobooth Express Karya Gen Z yang Fleksibel dan Kreatif". Berita ini mengangkat perjalanan Photomate sebagai layanan photobooth express yang digerakkan anak muda dengan pendekatan yang fleksibel dan kreatif.

Tak lama setelah resmi berjalan, Photomate sudah menorehkan sejumlah pencapaian awal yang patut disyukuri.

Pertama, debut di LokaNesia 2025 yang diselenggarakan di Malang Creative Center (MCC). Hanya beberapa minggu setelah resmi berjalan, Photomate sudah hadir di ajang kreatif tersebut dan memperkenalkan layanan photobooth express kepada masyarakat.

Kedua, kolaborasi dengan SMA Negeri 1 Batu. Photobooth express hadir di acara sekolah dengan respons positif dari siswa dan pihak sekolah. Pengalaman ini menunjukkan bahwa photobox tidak hanya untuk wedding atau corporate, tetapi juga cocok untuk acara pendidikan dan komunitas sekolah.

Ketiga, partisipasi di Road to Malang Walking Race 2025. Photomate mendokumentasikan momen peserta dengan cetak HD dan file digital, sehingga setiap peserta bisa membawa pulang kenangan dari acara tersebut.

Diberitakan di media nasional dan ditambah tiga pencapaian di lapangan, Photomate terus berkomitmen menghadirkan layanan photobooth yang terjangkau, berkualitas, dan mudah dijangkau untuk berbagai jenis acara.`,
  },
];

export const blogArticles: BlogArticle[] = (window as any).CMS_DATA?.articles?.length > 0
  ? (window as any).CMS_DATA.articles
  : defaultArticles;

export function getArticleById(id: number): BlogArticle | undefined {
  return blogArticles.find((a) => a.id === id);
}
