import React, { useState, useRef, useEffect } from "react";

interface Message {
  role: "user" | "model";
  text: string;
}

const WA_NUMBER = "6287787405280";

const ChatbotFAB: React.FC = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState<Message[]>([
    {
      role: "model",
      text: "Halo! Saya Asisten AI Photomate. Ada yang bisa saya bantu hari ini? 📸✨\n\nAnda bisa menanyakan tentang harga paket sewa, mengecek ketersediaan tanggal acara, atau meminta rekomendasi paket.",
    },
  ]);
  const [inputValue, setInputValue] = useState("");
  const [isLoading, setIsLoading] = useState(false);

  const messagesEndRef = useRef<HTMLDivElement>(null);

  // Auto-scroll ke pesan terbawah
  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  };

  useEffect(() => {
    if (isOpen) {
      scrollToBottom();
    }
  }, [messages, isOpen]);

  // Fungsi mengirim pesan ke backend Laravel
  const handleSendMessage = async (textToSend: string) => {
    if (!textToSend.trim() || isLoading) return;

    const newMessages = [...messages, { role: "user" as const, text: textToSend }];
    setMessages(newMessages);
    setInputValue("");
    setIsLoading(true);

    try {
      // Siapkan riwayat chat untuk dikirim ke backend (lewati pesan penyambut pertama)
      const historyToSend = newMessages.slice(1, -1);

      const response = await fetch("/api/chatbot/chat", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({
          message: textToSend,
          history: historyToSend,
        }),
      });

      const data = await response.json();

      if (response.ok && data.success) {
        setMessages((prev) => [...prev, { role: "model", text: data.message }]);
      } else {
        setMessages((prev) => [
          ...prev,
          {
            role: "model",
            text: "Maaf, sistem sedang sibuk. Silakan coba beberapa saat lagi atau hubungi WhatsApp Admin kami secara langsung.",
          },
        ]);
      }
    } catch (error) {
      console.error("Chat error:", error);
      setMessages((prev) => [
        ...prev,
        {
          role: "model",
          text: "Koneksi internet bermasalah. Pastikan Anda terhubung ke internet.",
        },
      ]);
    } finally {
      setIsLoading(false);
    }
  };

  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      handleSendMessage(inputValue);
    }
  };

  // Quick Action Chips Handler
  const handleChipClick = (topic: string) => {
    let text = "";
    if (topic === "harga") text = "Boleh tahu pricelist sewa photobooth dan paket apa saja yang tersedia?";
    if (topic === "jadwal") text = "Bagaimana cara cek ketersediaan jadwal untuk tanggal acara saya?";
    if (topic === "rekomendasi") text = "Rekomendasikan paket photobooth yang cocok untuk acara saya dong.";
    
    if (text) {
      handleSendMessage(text);
    }
  };

  return (
    <div className="fixed bottom-[104px] right-8 z-50 flex flex-col items-end">
      {/* 💬 CHAT WINDOW */}
      {isOpen && (
        <div className="mb-4 w-[90vw] sm:w-[400px] h-[500px] rounded-2xl bg-white border border-gray-200 shadow-2xl flex flex-col overflow-hidden transition-all duration-300 animate-in fade-in slide-in-from-bottom-5">
          {/* Header */}
          <div className="bg-[#0f172a] text-white p-4 flex justify-between items-center shadow-sm">
            <div className="flex items-center gap-3">
              <div className="w-8 h-8 rounded-full bg-white flex items-center justify-center text-[#0f172a] font-bold text-sm">
                PM
              </div>
              <div>
                <h3 className="font-semibold text-sm">Photomate Assistant</h3>
                <p className="text-[10px] text-emerald-300 flex items-center gap-1 font-medium">
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" /> Online
                </p>
              </div>
            </div>
            <button
              onClick={() => setIsOpen(false)}
              className="text-white hover:text-gray-200 transition-colors p-1"
              aria-label="Tutup Chat"
            >
              <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          {/* Messages Area */}
          <div className="flex-1 p-4 overflow-y-auto bg-stone-50 space-y-4">
            {messages.map((msg, index) => (
              <div key={index} className={`flex ${msg.role === "user" ? "justify-end" : "justify-start"}`}>
                <div
                  className={`max-w-[80%] rounded-2xl px-4 py-3 text-sm whitespace-pre-wrap ${
                    msg.role === "user"
                      ? "bg-[#0d9488] text-white rounded-br-none"
                      : "bg-white text-gray-800 border border-gray-200 rounded-bl-none shadow-sm"
                  }`}
                >
                  {msg.text}
                </div>
              </div>
            ))}
            {isLoading && (
              <div className="flex justify-start">
                <div className="bg-white border border-gray-200 text-gray-400 rounded-2xl rounded-bl-none px-4 py-3 text-sm shadow-sm flex items-center gap-2">
                  <span className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" />
                  <span className="w-2 h-2 bg-gray-400 rounded-full animate-bounce [animation-delay:0.2s]" />
                  <span className="w-2 h-2 bg-gray-400 rounded-full animate-bounce [animation-delay:0.4s]" />
                </div>
              </div>
            )}
            <div ref={messagesEndRef} />
          </div>

          {/* Quick Reply Chips */}
          <div className="px-4 py-2 bg-stone-50 border-t border-gray-100 flex gap-2 overflow-x-auto scrollbar-none shrink-0">
            <button
              onClick={() => handleChipClick("harga")}
              className="text-xs shrink-0 px-3 py-1.5 rounded-full bg-white border border-gray-200 text-gray-700 hover:border-[#0d9488] hover:text-[#0d9488] transition"
            >
              💰 Pricelist & Paket
            </button>
            <button
              onClick={() => handleChipClick("jadwal")}
              className="text-xs shrink-0 px-3 py-1.5 rounded-full bg-white border border-gray-200 text-gray-700 hover:border-[#0d9488] hover:text-[#0d9488] transition"
            >
              📅 Cek Jadwal
            </button>
            <button
              onClick={() => handleChipClick("rekomendasi")}
              className="text-xs shrink-0 px-3 py-1.5 rounded-full bg-white border border-gray-200 text-gray-700 hover:border-[#0d9488] hover:text-[#0d9488] transition"
            >
              🪄 Rekomendasi Paket
            </button>
          </div>

          {/* Footer Input */}
          <div className="p-3 bg-white border-t border-gray-200 flex gap-2 items-center">
            <textarea
              rows={1}
              value={inputValue}
              onChange={(e) => setInputValue(e.target.value)}
              onKeyDown={handleKeyPress}
              placeholder="Tulis pesan Anda..."
              className="flex-1 resize-none border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#0d9488] focus:border-[#0d9488]"
            />
            <button
              onClick={() => handleSendMessage(inputValue)}
              disabled={!inputValue.trim() || isLoading}
              className="p-2.5 rounded-xl bg-[#0d9488] text-white hover:bg-[#0f766e] transition disabled:opacity-40"
              aria-label="Kirim Pesan"
            >
              <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" />
              </svg>
            </button>
          </div>

          {/* WhatsApp Direct Escalation Button */}
          <a
            href={`https://wa.me/${WA_NUMBER}`}
            target="_blank"
            rel="noopener noreferrer"
            className="bg-emerald-500 hover:bg-emerald-600 text-white text-center py-2 text-xs font-semibold flex items-center justify-center gap-1.5 transition-colors"
          >
            Hubungi WhatsApp Admin Secara Langsung
          </a>
        </div>
      )}

      {/* 🔘 FLOATING BUTTON TRIGGER */}
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="w-14 h-14 rounded-full bg-[#0d9488] text-white shadow-2xl flex items-center justify-center hover:scale-105 transition-all duration-300"
        aria-label="Tanya AI Assistant"
      >
        {isOpen ? (
          <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
          </svg>
        ) : (
          <svg className="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
            />
          </svg>
        )}
      </button>
    </div>
  );
};

export default ChatbotFAB;
