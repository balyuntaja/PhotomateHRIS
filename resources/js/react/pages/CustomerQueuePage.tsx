import React, { useState, useEffect, useRef } from "react";
import { useParams, Link } from "react-router-dom";
import logoBlue from "../assets/img/logophotomateblue.png";

interface QueueState {
  event: {
    name: string;
    location: string | null;
    date: string;
    status: "DRAFT" | "OPEN" | "PAUSED" | "CLOSED";
  };
  now_serving: Array<{
    queue_number: number;
    formatted_number: string;
    device_id: number;
  }>;
  now_called: Array<{
    queue_number: number;
    formatted_number: string;
    device_id: number;
  }>;
  next_in_queue: Array<{
    queue_number: number;
    formatted_number: string;
  }>;
  stats: {
    waiting: number;
    called: number;
    serving: number;
    completed: number;
    skipped: number;
    cancelled: number;
  };
  my_entry: {
    id: number;
    queue_number: number;
    formatted_number: string;
    status: "WAITING" | "CALLED" | "SERVING" | "COMPLETED" | "SKIPPED" | "CANCELLED";
    device_id: number | null;
    people_ahead: number;
    joined_at: string | null;
  } | null;
}

function urlBase64ToUint8Array(base64String: string) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding)
    .replace(/\-/g, '+')
    .replace(/_/g, '/');

  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);

  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

export default function CustomerQueuePage() {
  const { eventCode } = useParams<{ eventCode: string }>();

  // Form states
  const [name, setName] = useState("");
  const [whatsapp, setWhatsapp] = useState("");
  const [email, setEmail] = useState("");
  const [dataConsent, setDataConsent] = useState(false);
  const [marketingConsent, setMarketingConsent] = useState(false);

  // Status/Data states
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [queueData, setQueueData] = useState<QueueState | null>(null);

  const [audioPermissionGranted, setAudioPermissionGranted] = useState(false);
  const [permissionModalDismissed, setPermissionModalDismissed] = useState(false);
  const [showPermissionModal, setShowPermissionModal] = useState(false);
  const [notificationPermission, setNotificationPermission] = useState<NotificationPermission>(
    typeof window !== "undefined" && "Notification" in window ? Notification.permission : "default"
  );
  const lastMyStatusRef = useRef<string | null>(null);
  const audioContextRef = useRef<AudioContext | null>(null);

  const registerPushSubscription = async (entryId: number, secureToken: string) => {
    if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
      console.log("[Push] Web push is not supported in this browser.");
      return;
    }

    try {
      console.log("[Push] Registering Service Worker...");
      const registration = await navigator.serviceWorker.register("/sw.js");
      console.log("[Push] Service Worker registered:", registration);

      await navigator.serviceWorker.ready;

      const vapidPublicKey = import.meta.env.VITE_VAPID_PUBLIC_KEY;
      if (!vapidPublicKey) {
        console.warn("[Push] VITE_VAPID_PUBLIC_KEY not set in frontend env.");
        return;
      }

      console.log("[Push] Subscribing to Push Manager...");
      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
      });
      console.log("[Push] Push subscription created:", subscription);

      const response = await fetch("/api/queue/push-subscription", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({
          queue_entry_id: entryId,
          secure_token: secureToken,
          subscription: subscription.toJSON()
        })
      });

      const result = await response.json();
      if (response.ok && result.success) {
        console.log("[Push] Push subscription saved to backend successfully.");
      } else {
        console.error("[Push] Failed to save push subscription to backend:", result.message);
      }
    } catch (err) {
      console.error("[Push] Error during push subscription setup:", err);
    }
  };

  const handleEnableNotifications = () => {
    setAudioPermissionGranted(true);
    setShowPermissionModal(false);
    setPermissionModalDismissed(true);

    // Haptic feedback vibration on permission activate
    if ("vibrate" in navigator) {
      try {
        navigator.vibrate([150, 80, 150]);
      } catch (e) {
        console.warn("Vibration error:", e);
      }
    }
    
    // Unlock AudioContext and play a quick warm preview chime
    try {
      const AudioContext = window.AudioContext || (window as any).webkitAudioContext;
      if (AudioContext) {
        if (!audioContextRef.current) {
          audioContextRef.current = new AudioContext();
        }
        const ctx = audioContextRef.current;
        if (ctx.state === "suspended") {
          ctx.resume();
        }
        // Subtle preview chime
        playChimeNote(ctx, 659.25, ctx.currentTime, 0.2, 0.3);
        playChimeNote(ctx, 1318.51, ctx.currentTime + 0.12, 0.35, 0.4);
      }
    } catch (e) {
      console.error("Failed to unlock AudioContext:", e);
    }

    // Request notification permission
    if (typeof window !== "undefined" && "Notification" in window) {
      if (Notification.permission === "default") {
        Notification.requestPermission()
          .then((permission) => {
            setNotificationPermission(permission);
            // If granted and we have valid queue entry, subscribe immediately!
            if (permission === "granted" && queueData?.my_entry) {
              const token = localStorage.getItem(`pm_queue_token_${eventCode}`);
              if (token) {
                registerPushSubscription(queueData.my_entry.id, token);
              }
            }
          })
          .catch((err) => {
            console.error("Failed to request notification permission:", err);
          });
      } else if (Notification.permission === "granted" && queueData?.my_entry) {
        const token = localStorage.getItem(`pm_queue_token_${eventCode}`);
        if (token) {
          registerPushSubscription(queueData.my_entry.id, token);
        }
      }
    }
  };

  // Interaction listener to enable audio/vibe capability
  useEffect(() => {
    const handleUserInteraction = () => {
      setAudioPermissionGranted(true);
      
      // Update notification permission status if available
      if (typeof window !== "undefined" && "Notification" in window) {
        setNotificationPermission(Notification.permission);
      }

      try {
        const AudioContext = window.AudioContext || (window as any).webkitAudioContext;
        if (AudioContext && !audioContextRef.current) {
          const ctx = new AudioContext();
          audioContextRef.current = ctx;

          // Unlock AudioContext by playing a tiny silence buffer (vital for iOS/Safari)
          const buffer = ctx.createBuffer(1, 1, 22050);
          const source = ctx.createBufferSource();
          source.buffer = buffer;
          source.connect(ctx.destination);
          source.start(0);

          if (ctx.resume) {
            ctx.resume();
          }
        } else if (audioContextRef.current && audioContextRef.current.state === "suspended") {
          audioContextRef.current.resume();
        }
      } catch (e) {
        console.error("Failed to unlock AudioContext:", e);
      }
    };

    window.addEventListener("click", handleUserInteraction);
    window.addEventListener("touchstart", handleUserInteraction);
    window.addEventListener("touchend", handleUserInteraction);
    window.addEventListener("keydown", handleUserInteraction);
    return () => {
      window.removeEventListener("click", handleUserInteraction);
      window.removeEventListener("touchstart", handleUserInteraction);
      window.removeEventListener("touchend", handleUserInteraction);
      window.removeEventListener("keydown", handleUserInteraction);
    };
  }, []);

  // Synchronize Push Subscription if user has already joined
  useEffect(() => {
    if (queueData?.my_entry) {
      const token = localStorage.getItem(`pm_queue_token_${eventCode}`);
      if (token && typeof window !== "undefined" && "Notification" in window && Notification.permission === "granted") {
        registerPushSubscription(queueData.my_entry.id, token);
      }
    }
  }, [queueData?.my_entry?.id]);

  // Auto prompt permission modal if user has active entry and not yet dismissed
  useEffect(() => {
    if (
      queueData?.my_entry &&
      (queueData.my_entry.status === "WAITING" || queueData.my_entry.status === "CALLED")
    ) {
      const isNotificationDefault =
        typeof window !== "undefined" && "Notification" in window && Notification.permission === "default";
      if (!audioPermissionGranted || isNotificationDefault) {
        if (!permissionModalDismissed) {
          setShowPermissionModal(true);
        }
      }
    }
  }, [queueData?.my_entry?.id, queueData?.my_entry?.status]);

  // Helper to play chime notes with harmonic overtone warmth using Web Audio API
  const playChimeNote = (ctx: AudioContext, freq: number, start: number, duration: number, volume = 0.45) => {
    try {
      // 1. Primary sine oscillator (fundamental tone)
      const osc1 = ctx.createOscillator();
      const gain1 = ctx.createGain();
      osc1.type = "sine";
      osc1.frequency.setValueAtTime(freq, start);
      gain1.gain.setValueAtTime(volume, start);
      gain1.gain.exponentialRampToValueAtTime(0.0001, start + duration);
      osc1.connect(gain1);
      gain1.connect(ctx.destination);
      osc1.start(start);
      osc1.stop(start + duration);

      // 2. Harmonic overtone (2x freq triangle wave) for crisp bell presence on phone speakers
      const osc2 = ctx.createOscillator();
      const gain2 = ctx.createGain();
      osc2.type = "triangle";
      osc2.frequency.setValueAtTime(freq * 2, start);
      gain2.gain.setValueAtTime(volume * 0.25, start);
      gain2.gain.exponentialRampToValueAtTime(0.0001, start + duration * 0.7);
      osc2.connect(gain2);
      gain2.connect(ctx.destination);
      osc2.start(start);
      osc2.stop(start + duration);
    } catch (e) {
      console.warn("Failed to play chime note:", e);
    }
  };

  // Extended melodic notification chime (~5 seconds duration)
  const playLongNotificationChime = (ctx: AudioContext) => {
    const now = ctx.currentTime;

    // Motif 1 (0.0s - 1.5s): Ascending 4-tone chime (E5 -> G#5 -> B5 -> E6)
    playChimeNote(ctx, 659.25, now + 0.00, 0.4, 0.45);  // E5
    playChimeNote(ctx, 830.61, now + 0.25, 0.4, 0.45);  // G#5
    playChimeNote(ctx, 987.77, now + 0.50, 0.4, 0.50);  // B5
    playChimeNote(ctx, 1318.51, now + 0.75, 0.8, 0.60); // E6

    // Motif 2 (1.6s - 3.0s): Second phrase repetition
    playChimeNote(ctx, 659.25, now + 1.60, 0.4, 0.45);  // E5
    playChimeNote(ctx, 830.61, now + 1.85, 0.4, 0.45);  // G#5
    playChimeNote(ctx, 987.77, now + 2.10, 0.4, 0.50);  // B5
    playChimeNote(ctx, 1318.51, now + 2.35, 0.8, 0.60); // E6

    // Motif 3 (3.2s - 5.0s): Attention finale bell sequence
    playChimeNote(ctx, 987.77, now + 3.20, 0.35, 0.45); // B5
    playChimeNote(ctx, 1318.51, now + 3.45, 0.35, 0.50); // E6
    playChimeNote(ctx, 987.77, now + 3.70, 0.35, 0.50); // B5
    playChimeNote(ctx, 1318.51, now + 3.95, 1.3, 0.65);  // E6 sustained
  };

  // Alert player (sound, speech, and vibration) when user is CALLED
  useEffect(() => {
    const myEntry = queueData?.my_entry;
    if (!myEntry) return;

    const currentStatus = myEntry.status;

    if (currentStatus === "CALLED" && lastMyStatusRef.current !== "CALLED") {
      // Play extended alert chime
      try {
        const ctx = audioContextRef.current;
        if (ctx && audioPermissionGranted) {
          if (ctx.state === "suspended") {
            ctx.resume();
          }
          playLongNotificationChime(ctx);
        }
      } catch (err) {
        console.error("Audio Context failed to play customer alert:", err);
      }

      // Voice announcement fallback/complement if supported
      if (typeof window !== "undefined" && "speechSynthesis" in window) {
        try {
          const text = `Nomor antrean ${myEntry.formatted_number}, giliran Anda telah dipanggil.`;
          const utterance = new SpeechSynthesisUtterance(text);
          utterance.lang = "id-ID";
          utterance.rate = 0.9;
          setTimeout(() => {
            window.speechSynthesis.speak(utterance);
          }, 1200);
        } catch (e) {
          console.warn("Speech synthesis error:", e);
        }
      }

      // Extended vibration pattern matching prolonged audio
      if ("vibrate" in navigator) {
        navigator.vibrate([500, 250, 500, 250, 500, 250, 800, 300, 800, 300, 1000]);
      }

      // Show native browser notification if permitted
      if (typeof window !== "undefined" && "Notification" in window && Notification.permission === "granted") {
        try {
          const title = "Giliran Anda Tiba! 🎉";
          const options = {
            body: `Nomor antrean Anda (${myEntry.formatted_number}) telah dipanggil. Silakan datang ke booth Photomate.`,
            icon: logoBlue,
            tag: "queue-called",
            requireInteraction: true,
          };
          new Notification(title, options);
        } catch (err) {
          console.error("Failed to show browser notification:", err);
        }
      }
    }

    lastMyStatusRef.current = currentStatus;
  }, [queueData, audioPermissionGranted]);

  const tokenKey = `pm_queue_token_${eventCode}`;
  const localToken = localStorage.getItem(tokenKey);

  // Fetch queue status from API
  const fetchQueueStatus = async (showLoading = false) => {
    if (showLoading) setLoading(true);
    try {
      const token = localStorage.getItem(tokenKey);
      const url = `/api/queue/${eventCode}` + (token ? `?token=${token}` : "");
      
      const response = await fetch(url, {
        headers: {
          Accept: "application/json",
        },
      });

      const result = await response.json();
      
      if (response.ok && result.success) {
        setQueueData(result.data);
        setErrorMsg(null);
      } else {
        setErrorMsg(result.message || "Gagal mengambil data antrean.");
      }
    } catch (err) {
      console.error(err);
      setErrorMsg("Koneksi jaringan terputus. Silakan coba beberapa saat lagi.");
    } finally {
      if (showLoading) setLoading(false);
    }
  };

  // Initial load and polling
  useEffect(() => {
    fetchQueueStatus(true);

    const interval = setInterval(() => {
      fetchQueueStatus(false);
    }, 5000); // Poll every 5 seconds

    return () => clearInterval(interval);
  }, [eventCode]);

  // Join queue submit handler
  const handleJoinQueue = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dataConsent) {
      alert("Anda harus menyetujui pemrosesan data untuk antrean.");
      return;
    }

    setSubmitting(true);
    setErrorMsg(null);

    try {
      const response = await fetch(`/api/queue/${eventCode}/join`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({
          name,
          whatsapp,
          email,
          marketing_consent: marketingConsent,
        }),
      });

      const result = await response.json();

      if (response.ok && result.success) {
        localStorage.setItem(tokenKey, result.data.secure_token);
        await fetchQueueStatus(true);

        // Haptic feedback vibration upon joining successfully
        if ("vibrate" in navigator) {
          try {
            navigator.vibrate([200, 100, 200]);
          } catch (vErr) {
            console.warn("Vibration error:", vErr);
          }
        }

        // Trigger permission pop-up modal immediately!
        setShowPermissionModal(true);
        setPermissionModalDismissed(false);
      } else if (response.status === 409 && result.data?.secure_token) {
        // Customer already has an active entry, save token and fetch
        localStorage.setItem(tokenKey, result.data.secure_token);
        await fetchQueueStatus(true);

        if ("vibrate" in navigator) {
          try {
            navigator.vibrate([150, 100, 150]);
          } catch (vErr) {
            console.warn("Vibration error:", vErr);
          }
        }

        setShowPermissionModal(true);
        setPermissionModalDismissed(false);
      } else {
        setErrorMsg(result.message || "Gagal bergabung ke antrean.");
      }
    } catch (err) {
      console.error(err);
      setErrorMsg("Gagal mendaftar. Periksa koneksi internet Anda.");
    } finally {
      setSubmitting(false);
    }
  };

  // Cancel queue handler
  const handleCancelQueue = async () => {
    if (!window.confirm("Apakah Anda yakin ingin membatalkan antrean Anda?")) {
      return;
    }

    const token = localStorage.getItem(tokenKey);
    if (!token) return;

    setLoading(true);
    try {
      const response = await fetch(`/api/queue/entry/${token}/cancel`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
        },
      });

      const result = await response.json();
      if (response.ok && result.success) {
        // Clear token from localStorage so they can join again if wanted
        localStorage.removeItem(tokenKey);
        fetchQueueStatus(true);
      } else {
        alert(result.message || "Gagal membatalkan antrean.");
      }
    } catch (err) {
      console.error(err);
      alert("Terjadi kesalahan jaringan.");
    } finally {
      setLoading(false);
    }
  };

  // Oncompleted cleanup to allow joining new queue
  const handleClearCompleted = () => {
    localStorage.removeItem(tokenKey);
    // Refresh page state
    setName("");
    setWhatsapp("");
    setEmail("");
    setDataConsent(false);
    setMarketingConsent(false);
    fetchQueueStatus(true);
  };

  if (loading && !queueData) {
    return (
      <div className="min-h-screen bg-linear-to-b from-primary/5 to-white flex items-center justify-center p-4">
        <div className="text-center">
          <div className="w-12 h-12 border-4 border-primary border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
          <p className="text-gray-600 font-medium">Memuat sistem antrean...</p>
        </div>
      </div>
    );
  }

  if (errorMsg && !queueData) {
    return (
      <div className="min-h-screen bg-linear-to-b from-primary/5 to-white flex items-center justify-center p-4">
        <div className="w-full max-w-md bg-white rounded-2xl border border-gray-100 shadow-xl p-6 text-center">
          <div className="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4 text-red-600">
            <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>
          <h3 className="text-xl font-bold text-gray-900 mb-2">Terjadi Kesalahan</h3>
          <p className="text-gray-600 text-sm mb-6">{errorMsg}</p>
          <Link to="/" className="inline-block py-2.5 px-6 rounded-xl bg-primary text-white font-semibold hover:bg-primary-dark transition-colors">
            Kembali ke Beranda
          </Link>
        </div>
      </div>
    );
  }

  const event = queueData?.event;
  const myEntry = queueData?.my_entry;

  return (
    <div className="min-h-screen bg-linear-to-b from-primary/5 to-white flex flex-col justify-between">
      {/* Pop-up Modal for Audio & Notification Permission */}
      {myEntry && (myEntry.status === "WAITING" || myEntry.status === "CALLED") && showPermissionModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div className="bg-white rounded-3xl border border-gray-100 shadow-2xl p-6 max-w-sm w-full text-center relative animate-in fade-in zoom-in duration-200">
              {/* Close button */}
              <button 
                type="button"
                onClick={() => {
                  setShowPermissionModal(false);
                  setPermissionModalDismissed(true);
                }}
                className="absolute top-4 right-4 text-gray-400 hover:text-gray-600 p-1.5 rounded-full hover:bg-gray-100 transition cursor-pointer"
                aria-label="Tutup"
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>

              {/* Animated Bell Icon */}
              <div className="w-16 h-16 bg-primary/10 text-primary rounded-2xl flex items-center justify-center mx-auto mb-4 border border-primary/20 shadow-sm">
                <svg className="w-8 h-8 animate-bounce text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
              </div>

              <h3 className="text-xl font-extrabold text-gray-900 mb-1.5">
                Aktifkan Suara & Notifikasi
              </h3>
              
              <p className="text-xs text-gray-500 leading-relaxed mb-4">
                Agar Anda tidak ketinggalan saat nomor antrean dipanggil, aktifkan suara alarm dan izin notifikasi perangkat.
              </p>

              {/* Perks List */}
              <div className="bg-gray-50 border border-gray-100 rounded-2xl p-3.5 text-left text-xs space-y-2.5 mb-5">
                <div className="flex items-center gap-2.5 text-gray-700">
                  <span className="w-6 h-6 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0 text-xs font-bold">🔊</span>
                  <span className="font-medium">Suara alarm panggilan antrean</span>
                </div>
                <div className="flex items-center gap-2.5 text-gray-700">
                  <span className="w-6 h-6 rounded-lg bg-sky-100 text-sky-600 flex items-center justify-center shrink-0 text-xs font-bold">📳</span>
                  <span className="font-medium">Getaran HP saat nomor dipanggil</span>
                </div>
                <div className="flex items-center gap-2.5 text-gray-700">
                  <span className="w-6 h-6 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0 text-xs font-bold">🔔</span>
                  <span className="font-medium">Pemberitahuan layar kunci & web push</span>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="space-y-2">
                <button
                  type="button"
                  onClick={handleEnableNotifications}
                  className="w-full py-3.5 px-4 rounded-xl bg-primary text-white font-bold hover:bg-primary-dark transition shadow-md hover:shadow-lg cursor-pointer flex items-center justify-center gap-2"
                >
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                  </svg>
                  <span>Aktifkan Sekarang</span>
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setShowPermissionModal(false);
                    setPermissionModalDismissed(true);
                  }}
                  className="w-full py-2.5 px-4 text-xs font-semibold text-gray-400 hover:text-gray-600 cursor-pointer transition"
                >
                  Nanti Saja
                </button>
              </div>
            </div>
          </div>
        )
      }

      {/* Decorative shapes */}
      <div className="pointer-events-none fixed inset-0 overflow-hidden">
        <div className="absolute -top-40 -right-40 h-96 w-96 rounded-full bg-primary/10 blur-3xl" />
        <div className="absolute bottom-0 -left-32 h-80 w-80 rounded-full bg-primary/10 blur-3xl" />
      </div>

      <header className="relative w-full py-5 px-4 text-center border-b border-gray-100 bg-white/50 backdrop-blur-xs flex justify-between items-center max-w-md mx-auto rounded-b-2xl shadow-xs">
        <Link to="/" className="flex items-center gap-1.5">
          <img src={logoBlue} alt="Logo" className="w-7 h-7" />
          <span className="font-extrabold text-primary tracking-wide text-lg">PHOTOMATE</span>
        </Link>
        {event && (
          <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${
            event.status === 'OPEN' ? 'bg-emerald-100 text-emerald-800' :
            event.status === 'PAUSED' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-800'
          }`}>
            {event.status === 'OPEN' ? 'Antrean Dibuka' :
             event.status === 'PAUSED' ? 'Ditangguhkan' : 'Ditutup'}
          </span>
        )}
      </header>

      <main className="relative flex-1 w-full max-w-md mx-auto px-4 py-8 flex flex-col justify-center">
        {event && (
          <div className="mb-6 text-center">
            <h1 className="text-xl font-extrabold text-gray-900 line-clamp-1">{event.name}</h1>
            <p className="text-xs text-gray-500 mt-1 flex items-center justify-center gap-1">
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              {event.location || "Lokasi Event"}
            </p>
          </div>
        )}

        {errorMsg && (
          <div className="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 text-xs font-semibold rounded-xl text-center">
            {errorMsg}
          </div>
        )}

        {/* 1. TICKET VIEW (IF CUSTOMER HAS JOINED ACTIVE QUEUE) */}
        {myEntry && (myEntry.status === 'WAITING' || myEntry.status === 'CALLED' || myEntry.status === 'SERVING') && (
          <div className="bg-white rounded-3xl border border-gray-100 shadow-xl overflow-hidden text-center flex flex-col justify-between">
            {/* Header Ticket */}
            <div className="p-6 bg-primary text-white">
              <p className="text-xs uppercase font-extrabold tracking-widest text-primary-200">Nomor Antrean Anda</p>
              <h2 className="text-5xl font-black mt-2 tracking-tight">{myEntry.formatted_number}</h2>
              
              <div className="mt-4 flex items-center justify-center gap-1.5 text-sm bg-white/10 py-1.5 px-3 rounded-full w-fit mx-auto">
                <span className="w-2.5 h-2.5 bg-emerald-400 rounded-full animate-pulse"></span>
                <span className="font-semibold text-white/90">
                  {myEntry.status === 'WAITING' ? 'Dalam Antrean' :
                   myEntry.status === 'CALLED' ? 'Dipanggil' : 'Sedang Foto'}
                </span>
              </div>
            </div>

            {/* Content Ticket */}
            <div className="p-6 space-y-6 flex-1 flex flex-col justify-center">
              {/* Permission reminder if dismissed but still not granted */}
              {(!audioPermissionGranted || (typeof window !== "undefined" && "Notification" in window && notificationPermission === "default")) && (
                <button
                  type="button"
                  onClick={() => setShowPermissionModal(true)}
                  className="w-full py-2.5 px-3 bg-primary/10 hover:bg-primary/15 text-primary-700 text-xs font-bold rounded-2xl border border-primary/20 flex items-center justify-center gap-1.5 transition cursor-pointer animate-pulse"
                >
                  <span>🔔</span>
                  <span>Aktifkan Suara & Notifikasi Panggilan</span>
                </button>
              )}

              {typeof window !== "undefined" && "Notification" in window && notificationPermission === "denied" && (
                <div className="p-3.5 bg-amber-50 border border-amber-200 text-amber-900 text-xs rounded-2xl text-center space-y-1">
                  <p className="font-bold flex items-center justify-center gap-1">
                    <span>⚠️</span>
                    <span>Izin Notifikasi Diblokir</span>
                  </p>
                  <p className="text-[11px] text-amber-700 leading-snug">
                    Aktifkan izin notifikasi di pengaturan browser Anda agar HP dapat bergetar & membunyikan alarm saat antrean dipanggil.
                  </p>
                </div>
              )}

              {myEntry.status === 'WAITING' && (
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-4 divide-x divide-gray-100">
                    <div>
                      <p className="text-[10px] text-gray-400 uppercase font-extrabold">Antrean Saat Ini</p>
                      <p className="text-2xl font-black text-gray-900 mt-1">
                        {queueData?.now_serving[0]?.formatted_number || queueData?.now_called[0]?.formatted_number || '-'}
                      </p>
                    </div>
                    <div>
                      <p className="text-[10px] text-gray-400 uppercase font-extrabold">Sisa Antrean</p>
                      <p className="text-2xl font-black text-primary mt-1">{myEntry.people_ahead} orang</p>
                    </div>
                  </div>

                  <hr className="border-gray-100" />

                  {myEntry.people_ahead <= 2 ? (
                    <div className="p-4 bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl text-center space-y-1">
                      <h4 className="text-sm font-bold">Giliran Anda segera tiba! ⚠️</h4>
                      <p className="text-xs text-amber-700">Mohon bersiap-siap dan merapat ke dekat area booth Photomate.</p>
                    </div>
                  ) : (
                    <div className="p-4 bg-sky-50 border border-sky-100 text-sky-900 rounded-2xl text-center">
                      <p className="text-xs text-sky-800 font-medium">Anda dapat menikmati acara terlebih dahulu. Kami akan panggil ketika nomor Anda hampir tiba.</p>
                    </div>
                  )}
                </div>
              )}

              {myEntry.status === 'CALLED' && (
                <div className="space-y-4 py-4 animate-bounce">
                  <div className="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto shadow-md">
                    <svg className="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                  </div>
                  <div className="space-y-2">
                    <h3 className="text-xl font-extrabold text-emerald-800">Giliran Anda Tiba! 🎉</h3>
                    <p className="text-xs text-emerald-700 font-medium">Silakan datang ke booth Photomate dan tunjukkan nomor ini ke operator.</p>
                    {myEntry.device_id && (
                      <div className="mt-3 inline-block bg-emerald-600 text-white text-xs font-bold px-4 py-1.5 rounded-full shadow-sm">
                        Silakan ke Booth photomate
                      </div>
                    )}
                  </div>
                </div>
              )}

              {myEntry.status === 'SERVING' && (
                <div className="space-y-4 py-4">
                  <div className="w-16 h-16 bg-primary/10 text-primary rounded-full flex items-center justify-center mx-auto animate-pulse">
                    <svg className="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                  </div>
                  <div className="space-y-1">
                    <h3 className="text-lg font-bold text-gray-900">Sedang Difoto...</h3>
                    <p className="text-xs text-gray-500">Nikmati pemotretan Anda di Device {myEntry.device_id || 1}! 📸</p>
                  </div>
                </div>
              )}
            </div>

            {/* Footer Ticket */}
            <div className="p-4 bg-gray-50 border-t border-gray-100">
              <button
                type="button"
                onClick={handleCancelQueue}
                className="w-full text-center text-xs text-red-600 hover:text-red-500 font-bold py-2 cursor-pointer transition-colors"
              >
                Batalkan Antrean
              </button>
            </div>
          </div>
        )}

        {/* 2. COMPLETED / SKIPPED STATE VIEW */}
        {myEntry && (myEntry.status === 'COMPLETED' || myEntry.status === 'SKIPPED' || myEntry.status === 'CANCELLED') && (
          <div className="bg-white rounded-3xl border border-gray-100 shadow-xl p-6 text-center space-y-6">
            {myEntry.status === 'COMPLETED' && (
              <>
                <div className="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto">
                  <svg className="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <div>
                  <h3 className="text-xl font-bold text-gray-950">Selesai Berfoto! 📸</h3>
                  <p className="text-sm text-gray-600 mt-2 leading-relaxed">
                    Terima kasih telah menggunakan layanan photobooth Photomate! Kami harap Anda menyukai hasilnya.
                  </p>
                </div>
              </>
            )}

            {myEntry.status === 'SKIPPED' && (
              <>
                <div className="w-16 h-16 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto">
                  <svg className="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                </div>
                <div>
                  <h3 className="text-xl font-bold text-gray-950">Nomor Terlewat</h3>
                  <p className="text-sm text-gray-600 mt-2 leading-relaxed">
                    Mohon maaf, nomor antrean Anda ({myEntry.formatted_number}) telah dilewati karena tidak berada di lokasi saat dipanggil.
                  </p>
                  <p className="text-xs text-gray-500 mt-2">
                    Silakan hubungi operator kami di booth jika Anda masih ingin berfoto.
                  </p>
                </div>
              </>
            )}

            {myEntry.status === 'CANCELLED' && (
              <>
                <div className="w-16 h-16 bg-gray-100 text-gray-500 rounded-full flex items-center justify-center mx-auto">
                  <svg className="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </div>
                <div>
                  <h3 className="text-xl font-bold text-gray-950">Antrean Dibatalkan</h3>
                  <p className="text-sm text-gray-600 mt-2">
                    Antrean Anda telah dibatalkan.
                  </p>
                </div>
              </>
            )}

            <button
              type="button"
              onClick={handleClearCompleted}
              className="w-full py-3.5 px-4 rounded-xl bg-primary text-white font-semibold hover:bg-primary-dark cursor-pointer transition-colors shadow-sm"
            >
              Gabung Antrean Baru
            </button>
          </div>
        )}

        {/* 3. JOIN FORM VIEW (IF NOT IN QUEUE AND EVENT STATUS IS OPEN) */}
        {!myEntry && event && event.status === 'OPEN' && (
          <div className="bg-white rounded-3xl border border-gray-100 shadow-xl p-6">
            <div className="text-center mb-6">
              <h2 className="text-lg font-bold text-gray-950">Gabung Antrean Digital</h2>
              <p className="text-xs text-gray-500 mt-1">Dapatkan nomor antrean secara online tanpa perlu berdiri mengantre di depan booth.</p>
            </div>

            <form onSubmit={handleJoinQueue} className="space-y-4">
              <div>
                <label htmlFor="name" className="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1.5">Nama Lengkap</label>
                <input
                  type="text"
                  id="name"
                  required
                  placeholder="Masukkan nama panggilan Anda"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition"
                />
              </div>

              <div>
                <label htmlFor="whatsapp" className="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1.5">Nomor WhatsApp</label>
                <input
                  type="tel"
                  id="whatsapp"
                  required
                  placeholder="Contoh: 08123456789"
                  value={whatsapp}
                  onChange={(e) => setWhatsapp(e.target.value)}
                  className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition"
                />
              </div>

              <div>
                <label htmlFor="email" className="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1.5">Alamat Email</label>
                <input
                  type="email"
                  id="email"
                  required
                  placeholder="Contoh: nama@email.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition"
                />
              </div>

              <div className="space-y-3 pt-2">
                <label className="flex items-start gap-2.5 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={dataConsent}
                    onChange={(e) => setDataConsent(e.target.checked)}
                    className="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary"
                  />
                  <span className="text-[11px] text-gray-600 leading-normal">
                    <span className="text-red-500">*</span> Saya setuju data saya digunakan untuk kepentingan sistem antrean Photomate.
                  </span>
                </label>

                <label className="flex items-start gap-2.5 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={marketingConsent}
                    onChange={(e) => setMarketingConsent(e.target.checked)}
                    className="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary"
                  />
                  <span className="text-[11px] text-gray-600 leading-normal">
                    Saya bersedia menerima promosi dan informasi resmi dari Photomate.
                  </span>
                </label>
              </div>

              <button
                type="submit"
                disabled={submitting}
                className="w-full mt-4 py-3.5 px-4 rounded-xl bg-primary text-white font-semibold hover:bg-primary-dark transition shadow-sm hover:shadow-md cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center"
              >
                {submitting ? (
                  <>
                    <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin mr-2"></div>
                    Mendaftarkan...
                  </>
                ) : (
                  "Bergabung ke Antrean"
                )}
              </button>
            </form>
          </div>
        )}

        {/* 4. EVENT STATE MESSAGES FOR BLOCKED STATES */}
        {!myEntry && event && event.status !== 'OPEN' && (
          <div className="bg-white rounded-3xl border border-gray-100 shadow-xl p-6 text-center space-y-5">
            <div className="w-16 h-16 bg-amber-50 text-amber-500 rounded-full flex items-center justify-center mx-auto">
              <svg className="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 15v2m0-8v6m0-8a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            
            <div>
              <h3 className="text-lg font-bold text-gray-950">
                {event.status === 'DRAFT' ? 'Antrean Belum Dibuka' :
                 event.status === 'PAUSED' ? 'Antrean Ditangguhkan' : 'Antrean Ditutup'}
              </h3>
              <p className="text-xs text-gray-600 mt-2 leading-relaxed">
                {event.status === 'DRAFT' ? 'Pendaftaran antrean belum resmi dibuka oleh operator. Silakan hubungi petugas kami.' :
                 event.status === 'PAUSED' ? 'Pendaftaran antrean baru sedang dihentikan sementara waktu (misal: perawatan alat). Mohon coba beberapa saat lagi.' :
                 'Pendaftaran antrean untuk event ini telah resmi berakhir. Terima kasih.'}
              </p>
            </div>

            <Link to="/" className="inline-block py-2.5 px-6 rounded-xl border-2 border-gray-200 text-gray-800 font-semibold hover:border-primary hover:text-primary transition-colors text-xs">
              Kembali ke Beranda
            </Link>
          </div>
        )}
      </main>

      <footer className="relative w-full py-4 text-center text-[10px] text-gray-400 max-w-md mx-auto">
        &copy; {new Date().getFullYear()} Photomate.id. All rights reserved.
      </footer>
    </div>
  );
}
