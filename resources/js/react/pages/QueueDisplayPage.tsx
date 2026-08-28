import React, { useState, useEffect, useRef } from "react";
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

  const [audioPermissionGranted, setAudioPermissionGranted] = useState(false);
  const lastCalledNumberRef = useRef<string | null>(null);

  // Play chime sound using Web Audio API
  const playChime = () => {
    try {
      const AudioContext = window.AudioContext || (window as any).webkitAudioContext;
      if (!AudioContext) return;
      const ctx = new AudioContext();
      const now = ctx.currentTime;
      
      // Play E5 (659.25 Hz) followed by C5 (523.25 Hz)
      const osc1 = ctx.createOscillator();
      const gain1 = ctx.createGain();
      osc1.type = "sine";
      osc1.frequency.setValueAtTime(659.25, now);
      gain1.gain.setValueAtTime(0.3, now);
      gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
      osc1.connect(gain1);
      gain1.connect(ctx.destination);
      osc1.start(now);
      osc1.stop(now + 0.5);
      
      const osc2 = ctx.createOscillator();
      const gain2 = ctx.createGain();
      osc2.type = "sine";
      osc2.frequency.setValueAtTime(523.25, now + 0.25);
      gain2.gain.setValueAtTime(0.3, now + 0.25);
      gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.25 + 0.6);
      osc2.connect(gain2);
      gain2.connect(ctx.destination);
      osc2.start(now + 0.25);
      osc2.stop(now + 0.25 + 0.6);
    } catch (err) {
      console.error("Audio Context failed to play chime:", err);
    }
  };

  // Speak announcement using SpeechSynthesis
  const speakAnnouncement = (formattedNumber: string) => {
    if (!("speechSynthesis" in window)) return;
    
    // Spell out letters and numbers for natural pronunciation (e.g. A001 -> A nol nol satu)
    const spelled = formattedNumber
      .split("")
      .map((char) => {
        if (char === "0") return "nol";
        return char;
      })
      .join(" ");

    const text = `Nomor antrean, ${spelled}. Silakan menuju booth foto.`;
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = "id-ID";
    utterance.rate = 0.85; // Natural speed
    utterance.pitch = 1.0;

    playChime();
    setTimeout(() => {
      window.speechSynthesis.speak(utterance);
    }, 500);
  };

  // Listen to user interaction to enable audio permissions
  useEffect(() => {
    const handleUserInteraction = () => {
      setAudioPermissionGranted(true);
      try {
        const AudioContext = window.AudioContext || (window as any).webkitAudioContext;
        if (AudioContext) {
          const ctx = new AudioContext();
          if (ctx.state === "suspended") {
            ctx.resume();
          }
        }
      } catch (e) {}
    };

    window.addEventListener("click", handleUserInteraction);
    window.addEventListener("keydown", handleUserInteraction);
    return () => {
      window.removeEventListener("click", handleUserInteraction);
      window.removeEventListener("keydown", handleUserInteraction);
    };
  }, []);

  // Monitor when a queue entry changes to CALLED status to announce it
  useEffect(() => {
    if (!displayData || !audioPermissionGranted) return;

    // Find if any entry is being called (status CALLED in now_called)
    const currentCalled = displayData.now_called.find((e) => e.device_id === 1) || displayData.now_called[0];

    if (currentCalled) {
      const num = currentCalled.formatted_number;
      if (lastCalledNumberRef.current !== num) {
        lastCalledNumberRef.current = num;
        speakAnnouncement(num);
      }
    } else {
      // Clear ref so recall trigger is possible if same number gets called again
      lastCalledNumberRef.current = null;
    }
  }, [displayData, audioPermissionGranted]);

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

  // Match serving entries (default to device_id 1, fallback to first entry)
  const activeServing = nowServing.find((e) => e.device_id === 1) || nowCalled.find((e) => e.device_id === 1) || nowServing[0] || nowCalled[0];

  return (
    <div className="min-h-screen bg-gray-950 text-white flex flex-col justify-between p-6 md:p-12 overflow-hidden select-none font-sans">
      {/* Autoplay Audio Banner */}
      {!audioPermissionGranted && (
        <div className="fixed top-0 left-0 right-0 z-50 bg-primary/20 backdrop-blur-md text-primary border-b border-primary/30 text-center py-3 px-4 font-bold text-sm animate-pulse cursor-pointer">
          🔊 Ketuk di mana saja pada layar TV ini untuk mengaktifkan Notifikasi Suara Panggilan
        </div>
      )}

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

      {/* Main Grid: Now Serving & Next In Queue */}
      <div className="flex-1 grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
        {/* Active Serving Card */}
        <div className="bg-gray-900/60 backdrop-blur-md rounded-4xl border border-gray-800 p-8 flex flex-col justify-between items-center text-center shadow-2xl relative overflow-hidden">
          <div className="absolute top-4 left-6 bg-primary/20 text-primary border border-primary/30 text-xs font-bold py-1.5 px-4 rounded-full uppercase tracking-wider">
            Panggilan Utama
          </div>

          <div className="my-auto py-12">
            <p className="text-gray-400 uppercase tracking-widest text-lg font-bold mb-4">SEdANG DIPANGGIL / DIFOTO</p>
            {activeServing ? (
              <h2 className="text-9xl md:text-[11rem] font-black text-white tracking-tighter drop-shadow-[0_10px_20px_rgba(54,78,113,0.3)] animate-pulse">
                {activeServing.formatted_number}
              </h2>
            ) : (
              <h2 className="text-7xl md:text-8xl font-black text-gray-700 tracking-wider uppercase">
                KOSONG
              </h2>
            )}
          </div>
          
          <div className="w-full border-t border-gray-800/50 pt-4 flex justify-center items-center text-gray-500 text-sm">
            Silakan menuju booth foto saat nomor Anda dipanggil
          </div>
        </div>

        {/* Next In Queue Card */}
        <div className="bg-gray-900/60 backdrop-blur-md rounded-4xl border border-gray-800 p-8 flex flex-col justify-between items-center text-center shadow-2xl relative overflow-hidden">
          <div className="absolute top-4 left-6 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-xs font-bold py-1.5 px-4 rounded-full uppercase tracking-wider">
            Antrean Berikutnya
          </div>

          <div className="my-auto w-full py-6 flex flex-col items-center">
            {nextInQueue.length > 0 ? (
              <div className="grid grid-cols-2 gap-4 w-full px-4">
                {nextInQueue.slice(0, 6).map((item, index) => (
                  <div
                    key={index}
                    className="bg-gray-950/40 border border-gray-800/80 rounded-2xl py-4 px-6 text-3xl md:text-4xl font-extrabold text-emerald-400 shadow-md flex items-center justify-center gap-3 transition-transform hover:scale-105"
                  >
                    <span className="text-xs font-bold text-gray-500 uppercase tracking-wider">
                      #{index + 1}
                    </span>
                    {item.formatted_number}
                  </div>
                ))}
              </div>
            ) : (
              <h2 className="text-5xl md:text-6xl font-black text-gray-700 tracking-wider uppercase py-12">
                KOSONG
              </h2>
            )}
          </div>

          <div className="w-full border-t border-gray-800/50 pt-4 flex justify-center items-center text-gray-500 text-sm">
            Harap bersiap saat nomor Anda mendekati giliran
          </div>
        </div>
      </div>

      {/* Footer Banner */}
      <div className="bg-gray-900/40 backdrop-blur-md border border-gray-800 rounded-3xl p-6 md:p-8 flex items-center justify-center text-center shadow-xl">
        <p className="text-gray-400 font-medium text-lg md:text-xl">
          Silakan bersiap jika nomor antrean Anda tertera di kolom <span className="text-emerald-400 font-extrabold">Antrean Berikutnya</span>. 
          Menuju ke booth foto saat nomor Anda dipanggil.
        </p>
      </div>
    </div>
  );
}
