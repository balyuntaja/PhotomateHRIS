import React, { useState, useEffect } from "react";
import { useParams, Link } from "react-router-dom";
import logoBlue from "../assets/img/logophotomateblue.png";

interface DisplayState {
  event: {
    name: string;
    status: "DRAFT" | "OPEN" | "PAUSED" | "CLOSED";
  };
  now_serving: Array<{
    formatted_number: string;
    device_id: number;
  }>;
  now_called: Array<{
    formatted_number: string;
    device_id: number;
  }>;
  next_in_queue: Array<{
    formatted_number: string;
  }>;
}

export default function QueueDisplayPage() {
  const { eventCode } = useParams<{ eventCode: string }>();

  const [loading, setLoading] = useState(true);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [displayData, setDisplayData] = useState<DisplayState | null>(null);

  // Fetch display data
  const fetchDisplayData = async (showLoading = false) => {
    if (showLoading) setLoading(true);
    try {
      const response = await fetch(`/api/queue/${eventCode}/display`, {
        headers: {
          Accept: "application/json",
        },
      });

      const result = await response.json();

      if (response.ok && result.success) {
        setDisplayData(result.data);
        setErrorMsg(null);
      } else {
        setErrorMsg(result.message || "Gagal memuat data display.");
      }
    } catch (err) {
      console.error(err);
      setErrorMsg("Koneksi jaringan bermasalah.");
    } finally {
      if (showLoading) setLoading(false);
    }
  };

  useEffect(() => {
    fetchDisplayData(true);

    const interval = setInterval(() => {
      fetchDisplayData(false);
    }, 3000); // Poll every 3 seconds for maximum responsiveness on TV

    return () => clearInterval(interval);
  }, [eventCode]);

  if (loading && !displayData) {
    return (
      <div className="min-h-screen bg-gray-950 text-white flex items-center justify-center">
        <div className="text-center">
          <div className="w-16 h-16 border-4 border-primary border-t-transparent rounded-full animate-spin mx-auto mb-6"></div>
          <p className="text-xl text-gray-400 font-medium">Memuat layar antrean venue...</p>
        </div>
      </div>
    );
  }

  if (errorMsg && !displayData) {
    return (
      <div className="min-h-screen bg-gray-950 text-white flex items-center justify-center p-4">
        <div className="max-w-md w-full p-8 rounded-3xl bg-gray-900 border border-gray-800 text-center space-y-6">
          <div className="w-16 h-16 bg-red-950/50 text-red-500 rounded-full flex items-center justify-center mx-auto border border-red-900">
            <svg className="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>
          <div>
            <h3 className="text-2xl font-bold">Layar Antrean Error</h3>
            <p className="text-gray-400 text-sm mt-2">{errorMsg}</p>
          </div>
          <Link to="/" className="inline-block py-3 px-8 rounded-2xl bg-primary text-white font-bold hover:bg-primary-dark transition-colors">
            Kembali ke Beranda
          </Link>
        </div>
      </div>
    );
  }

  const event = displayData?.event;
  const nowServing = displayData?.now_serving || [];
  const nowCalled = displayData?.now_called || [];
  const nextInQueue = displayData?.next_in_queue || [];

  // Match serving entries by device_id
  const device1Serving = nowServing.find((e) => e.device_id === 1) || nowCalled.find((e) => e.device_id === 1);
  const device2Serving = nowServing.find((e) => e.device_id === 2) || nowCalled.find((e) => e.device_id === 2);

  return (
    <div className="min-h-screen bg-gray-950 text-white flex flex-col justify-between p-6 md:p-12 overflow-hidden select-none font-sans">
      {/* Decorative Glow */}
      <div className="absolute top-0 left-1/4 w-96 h-96 bg-primary/10 rounded-full blur-3xl pointer-events-none"></div>
      <div className="absolute bottom-0 right-1/4 w-96 h-96 bg-primary/10 rounded-full blur-3xl pointer-events-none"></div>

      {/* Header */}
      <div className="flex flex-col md:flex-row justify-between items-center border-b border-gray-800 pb-6 mb-8 gap-4">
        <div className="flex items-center gap-3">
          <img src={logoBlue} alt="Logo" className="w-12 h-12" />
          <div>
            <h1 className="text-2xl md:text-3xl font-black tracking-wider text-primary">PHOTOMATE</h1>
            <p className="text-xs text-gray-500 uppercase tracking-widest font-bold">Digital Queue Display</p>
          </div>
        </div>
        {event && (
          <div className="text-center md:text-right">
            <h2 className="text-xl md:text-2xl font-extrabold text-gray-100">{event.name}</h2>
            <div className="mt-1 flex items-center justify-center md:justify-end gap-2">
              <span className={`inline-block w-2.5 h-2.5 rounded-full ${
                event.status === 'OPEN' ? 'bg-emerald-500 animate-ping' :
                event.status === 'PAUSED' ? 'bg-amber-500' : 'bg-red-500'
              }`}></span>
              <span className="text-xs font-bold text-gray-400">
                {event.status === 'OPEN' ? 'Panggilan Antrean Aktif' :
                 event.status === 'PAUSED' ? 'Antrean Ditangguhkan' : 'Antrean Ditutup'}
              </span>
            </div>
          </div>
        )}
      </div>

      {/* Main Grid: Now Serving */}
      <div className="flex-1 grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
        {/* Device 1 */}
        <div className="bg-gray-900/60 backdrop-blur-md rounded-4xl border border-gray-800 p-8 flex flex-col justify-between items-center text-center shadow-2xl relative overflow-hidden">
          <div className="absolute top-4 left-6 bg-primary/20 text-primary border border-primary/30 text-xs font-bold py-1.5 px-4 rounded-full uppercase tracking-wider">
            Device 01
          </div>

          <div className="my-auto py-12">
            <p className="text-gray-400 uppercase tracking-widest text-lg font-bold mb-4">SEdANG DIPANGGIL / DIFOTO</p>
            {device1Serving ? (
              <h2 className="text-9xl md:text-[11rem] font-black text-white tracking-tighter drop-shadow-[0_10px_20px_rgba(54,78,113,0.3)] animate-pulse">
                {device1Serving.formatted_number}
              </h2>
            ) : (
              <h2 className="text-7xl md:text-8xl font-black text-gray-700 tracking-wider uppercase">
                KOSONG
              </h2>
            )}
          </div>
          
          <div className="w-full border-t border-gray-800/50 pt-4 flex justify-center items-center text-gray-500 text-sm">
            Silakan menuju booth jika nomor Anda tertera
          </div>
        </div>

        {/* Device 2 */}
        <div className="bg-gray-900/60 backdrop-blur-md rounded-4xl border border-gray-800 p-8 flex flex-col justify-between items-center text-center shadow-2xl relative overflow-hidden">
          <div className="absolute top-4 left-6 bg-primary/20 text-primary border border-primary/30 text-xs font-bold py-1.5 px-4 rounded-full uppercase tracking-wider">
            Device 02
          </div>

          <div className="my-auto py-12">
            <p className="text-gray-400 uppercase tracking-widest text-lg font-bold mb-4">SEdANG DIPANGGIL / DIFOTO</p>
            {device2Serving ? (
              <h2 className="text-9xl md:text-[11rem] font-black text-white tracking-tighter drop-shadow-[0_10px_20px_rgba(54,78,113,0.3)] animate-pulse">
                {device2Serving.formatted_number}
              </h2>
            ) : (
              <h2 className="text-7xl md:text-8xl font-black text-gray-700 tracking-wider uppercase">
                KOSONG
              </h2>
            )}
          </div>

          <div className="w-full border-t border-gray-800/50 pt-4 flex justify-center items-center text-gray-500 text-sm">
            Silakan menuju booth jika nomor Anda tertera
          </div>
        </div>
      </div>

      {/* Footer Grid: Next In Queue */}
      <div className="bg-gray-900/40 backdrop-blur-md border border-gray-800 rounded-3xl p-6 md:p-8 flex flex-col md:flex-row items-center gap-6 justify-between">
        <div className="shrink-0 flex items-center gap-3">
          <div className="w-12 h-12 bg-primary/20 rounded-2xl flex items-center justify-center text-primary border border-primary/30">
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
            </svg>
          </div>
          <div>
            <h3 className="font-extrabold text-lg text-gray-200">BERIKUTNYA</h3>
            <p className="text-xs text-gray-500 uppercase tracking-wider">Antrean Berikutnya</p>
          </div>
        </div>

        <div className="flex-1 flex flex-wrap justify-center md:justify-start gap-4 md:pl-8">
          {nextInQueue.length > 0 ? (
            nextInQueue.map((item, index) => (
              <div
                key={index}
                className="bg-gray-800/80 border border-gray-700/50 rounded-2xl py-3 px-6 text-2xl font-extrabold text-gray-300 shadow-md flex items-center"
              >
                {item.formatted_number}
              </div>
            ))
          ) : (
            <div className="text-gray-600 font-medium text-lg">Belum ada antrean masuk...</div>
          )}
        </div>
      </div>
    </div>
  );
}
