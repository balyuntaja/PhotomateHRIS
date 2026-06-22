import { useCallback, useMemo, useState } from "react";
import { DayPicker } from "react-day-picker";
import "react-day-picker/style.css";
import { Link } from "react-router-dom";
import { Navbar } from "../components";

/* ================= TYPES ================= */

export interface EventDate {
  date: string; // YYYY-MM-DD
  used: number;
  capacity: number;
  title?: string;
}

export type AvailabilityStatus = "available" | "limited" | "fullyBooked";

export function getStatus(e: EventDate): AvailabilityStatus {
  if (e.used >= e.capacity) return "fullyBooked";
  if (e.used > 0) return "limited";
  return "available";
}

/* ================= CONFIG ================= */

const CAPACITY = 2;
const ITEMS_PER_PAGE = 3;

/** Event dengan rentang tanggal (satu baris untuk banyak hari) */
type EventRange = {
  title: string;
  startDate: string;
  endDate: string;
  used: number;
  capacity: number;
};

/** Event dengan daftar tanggal bebas (mis. wedding di beberapa hari terpisah) */
type EventDates = {
  title: string;
  dates: string[];
  used: number;
  capacity: number;
};

type EventDefinition = EventRange | EventDates;

function isRange(e: EventDefinition): e is EventRange {
  return "startDate" in e && "endDate" in e;
}

/** Generate array tanggal YYYY-MM-DD antara start dan end (inclusive) */
function dateRange(start: string, end: string): string[] {
  const out: string[] = [];
  const d = new Date(start + "T12:00:00");
  const endD = new Date(end + "T12:00:00");
  while (d <= endD) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    out.push(`${y}-${m}-${day}`);
    d.setDate(d.getDate() + 1);
  }
  return out;
}

function expandToEventDates(def: EventDefinition): EventDate[] {
  const dates = isRange(def)
    ? dateRange(def.startDate, def.endDate)
    : def.dates;
  return dates.map((date) => ({
    date,
    used: def.used,
    capacity: def.capacity,
    title: def.title,
  }));
}

const cmsSchedules: EventDefinition[] = ((window as any).CMS_DATA?.schedules || []).map((s: any) => ({
  title: s.title,
  startDate: s.startDate,
  endDate: s.endDate,
  used: Number(s.used),
  capacity: Number(s.capacity),
}));

/** Definisi event dalam bentuk ringkas (satu event = satu rentang atau beberapa tanggal) */
const EVENT_DEFINITIONS: EventDefinition[] = cmsSchedules.length > 0 ? cmsSchedules : [
  {
    title: "Ruang Riang Festival",
    startDate: "2026-03-05",
    endDate: "2026-03-15",
    used: 1,
    capacity: CAPACITY,
  },
  {
    title: "Wedding",
    dates: ["2026-03-28", "2026-05-31"],
    used: 1,
    capacity: CAPACITY,
  },
];

const DUMMY_EVENT_DATES: EventDate[] =
  EVENT_DEFINITIONS.flatMap(expandToEventDates);

const WA_NUMBER = "6287787405280";

/* ================= HELPERS ================= */

function formatDateForWA(d: Date): string {
  return d.toLocaleDateString("id-ID", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

function formatEventDate(dateStr: string): string {
  const [y, m, d] = dateStr.split("-").map(Number);
  const date = new Date(y, m - 1, d);
  return date.toLocaleDateString("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function toYYYYMMDD(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

function startOfDay(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}

function startOfMonth(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), 1);
}

function addMonths(d: Date, n: number): Date {
  const out = new Date(d);
  out.setMonth(out.getMonth() + n);
  return out;
}

function getStatusLabel(status: AvailabilityStatus): string {
  if (status === "fullyBooked") return "Penuh";
  if (status === "limited") return "Terbatas";
  return "Tersedia";
}

function getStatusDotClass(status: AvailabilityStatus): string {
  if (status === "fullyBooked") return "bg-purple-600";
  if (status === "limited") return "bg-sky-400";
  return "bg-emerald-500";
}

/** Satu baris di sidebar: satu event (range atau satu tanggal) */
export interface EventDisplayItem {
  id: string;
  title: string;
  dateLabel: string;
  used: number;
  capacity: number;
  status: AvailabilityStatus;
}

function buildEventDisplayList(
  definitions: EventDefinition[],
  dateMap: Map<string, EventDate>
): EventDisplayItem[] {
  const list: EventDisplayItem[] = [];
  for (const def of definitions) {
    if (isRange(def)) {
      const dates = dateRange(def.startDate, def.endDate);
      const statuses = dates.map((d) =>
        getStatus(
          dateMap.get(d) ?? {
            date: d,
            used: 0,
            capacity: def.capacity,
          }
        )
      );
      const worst: AvailabilityStatus = statuses.some((s) => s === "fullyBooked")
        ? "fullyBooked"
        : statuses.some((s) => s === "limited")
        ? "limited"
        : "available";
      const first = formatEventDate(dates[0]);
      const last = formatEventDate(dates[dates.length - 1]);
      const dateLabel =
        dates.length > 1 ? `${first} – ${last}` : first;
      list.push({
        id: `${def.title}-${def.startDate}`,
        title: def.title,
        dateLabel,
        used: def.used,
        capacity: def.capacity,
        status: worst,
      });
    } else {
      for (const date of def.dates) {
        const e = dateMap.get(date)!;
        list.push({
          id: `${def.title}-${date}`,
          title: def.title,
          dateLabel: formatEventDate(date),
          used: e.used,
          capacity: e.capacity,
          status: getStatus(e),
        });
      }
    }
  }
  return list;
}

/* ================= COMPONENT ================= */

const MONTHS_AHEAD = 24; // bisa cek jadwal sampai 24 bulan ke depan

export default function EventAvailability() {
  const [selectedDate, setSelectedDate] = useState<Date | undefined>();
  const [page, setPage] = useState(1);
  const [currentMonth, setCurrentMonth] = useState<Date>(() =>
    startOfMonth(new Date())
  );
  const [showSelectDateModal, setShowSelectDateModal] = useState(false);

  /* ---------- maps ---------- */

  const dateMap = useMemo(() => {
    const map = new Map<string, EventDate>();
    DUMMY_EVENT_DATES.forEach((e) => map.set(e.date, e));
    return map;
  }, []);

  const { fullyBookedDates, limitedDates } = useMemo(() => {
    const fullyBooked: Date[] = [];
    const limited: Date[] = [];

    DUMMY_EVENT_DATES.forEach((e) => {
      const status = getStatus(e);
      const [y, m, d] = e.date.split("-").map(Number);
      const date = new Date(y, m - 1, d);

      if (status === "fullyBooked") fullyBooked.push(date);
      else if (status === "limited") limited.push(date);
    });

    return { fullyBookedDates: fullyBooked, limitedDates: limited };
  }, []);

  const today = useMemo(() => startOfDay(new Date()), []);
  const startMonth = useMemo(() => startOfMonth(today), [today]);
  const endMonth = useMemo(
    () => addMonths(startOfMonth(today), MONTHS_AHEAD),
    [today]
  );

  const canGoPrevMonth = currentMonth.getTime() > startMonth.getTime();
  const canGoNextMonth =
    addMonths(currentMonth, 1).getTime() <= endMonth.getTime();

  const goPrevMonth = () => {
    setCurrentMonth((m) => addMonths(m, -1));
  };
  const goNextMonth = () => {
    setCurrentMonth((m) => addMonths(m, 1));
  };

  const currentMonthLabel = currentMonth.toLocaleDateString("id-ID", {
    month: "long",
    year: "numeric",
  });

  /* ---------- sidebar: satu baris per event (range atau satu tanggal) ---------- */

  const eventDisplayList = useMemo(
    () => buildEventDisplayList(EVENT_DEFINITIONS, dateMap),
    [dateMap]
  );

  const paginatedEvents = useMemo(() => {
    const start = (page - 1) * ITEMS_PER_PAGE;
    return eventDisplayList.slice(start, start + ITEMS_PER_PAGE);
  }, [page, eventDisplayList]);

  const totalPages = Math.ceil(eventDisplayList.length / ITEMS_PER_PAGE);

  /* ---------- WA link ---------- */

  const waHref = useMemo(() => {
    const text = selectedDate
      ? `Hai Photomate 👋 saya ingin cek ketersediaan photobooth untuk tanggal ${formatDateForWA(selectedDate)}`
      : "Hai Photomate 👋 saya ingin cek ketersediaan photobooth dan booking jadwal.";

    return `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent(text)}`;
  }, [selectedDate]);

  const selectedStatus = selectedDate
    ? getStatus(
        dateMap.get(toYYYYMMDD(selectedDate)) ?? {
          date: toYYYYMMDD(selectedDate),
          used: 0,
          capacity: CAPACITY,
        }
      )
    : null;

  /* ---------- custom day button ---------- */

  const DayButtonWithInfo = useCallback(
    (props: any) => {
      const { day, modifiers, children, className, ...rest } = props;

      const eventData = dateMap.get(day.isoDate);
      const status = eventData ? getStatus(eventData) : null;

      const label =
        status
          ? getStatusLabel(status)
          : !modifiers.outside && !modifiers.disabled
          ? "Tersedia"
          : null;

      const labelColor =
        status === "fullyBooked"
          ? "text-purple-600"
          : status === "limited"
          ? "text-sky-600"
          : status === "available"
          ? "text-emerald-600"
          : "text-gray-400";

      return (
        <button type="button" className={`${className} group`} {...rest}>
          <span className="block text-sm font-semibold tabular-nums">
            {children}
          </span>

          {label && (
            <span
              className={`mt-1 block text-[11px] font-medium opacity-80 group-hover:opacity-100 ${labelColor}`}
            >
              {label}
            </span>
          )}
        </button>
      );
    },
    [dateMap]
  );

  /* ================= RENDER ================= */

  return (
    <div className="min-h-screen bg-stone-50">
      <Navbar />

      <main className="pt-12 pb-16">
        <section className="w-full py-20">
          <div className="max-w-7xl mx-auto px-4 lg:px-8">
            <div className="grid grid-cols-12 gap-8 max-w-5xl mx-auto xl:max-w-full">
              
              {/* ================= LEFT ================= */}
              <div className="col-span-12 xl:col-span-5">
                <h2 className="text-3xl font-bold text-gray-900 mb-2">
                  Cek Ketersediaan Tanggal
                </h2>

                <p className="text-gray-600 mb-8">
                  Maksimal {CAPACITY} device per hari.
                </p>

                {/* Event list */}
                <div className="flex flex-col gap-4">
                  {paginatedEvents.map((item) => (
                    <div
                      key={item.id}
                      className="p-5 rounded-xl bg-white shadow-sm border border-gray-100 hover:shadow-md transition"
                    >
                      <div className="flex items-center gap-2 mb-2">
                        <span
                          className={`w-2.5 h-2.5 rounded-full ${getStatusDotClass(item.status)}`}
                        />
                        <p className="text-sm font-medium text-gray-900">
                          {item.dateLabel}
                        </p>
                      </div>

                      <h6 className="text-lg font-semibold text-gray-900">
                        {item.title}
                      </h6>

                      <p className="text-sm text-gray-600">
                        {item.used}/{item.capacity} device terpakai
                      </p>
                    </div>
                  ))}
                </div>

                {/* Pagination */}
                {totalPages > 1 && (
                  <div className="flex items-center justify-between mt-6">
                    <button
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                      disabled={page === 1}
                      className="px-4 py-2 rounded-lg border border-gray-200 text-sm disabled:opacity-40 hover:bg-gray-50"
                    >
                      ← Prev
                    </button>

                    <span className="text-sm text-gray-500">
                      Halaman {page} / {totalPages}
                    </span>

                    <button
                      onClick={() =>
                        setPage((p) => Math.min(totalPages, p + 1))
                      }
                      disabled={page === totalPages}
                      className="px-4 py-2 rounded-lg border border-gray-200 text-sm disabled:opacity-40 hover:bg-gray-50"
                    >
                      Next →
                    </button>
                  </div>
                )}

                {/* CTA: hanya bisa booking setelah pilih tanggal */}
                {selectedDate ? (
                  <a
                    href={waHref}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="mt-8 flex items-center justify-center gap-2 w-full py-4 rounded-2xl bg-primary text-white font-semibold shadow-lg hover:shadow-xl hover:bg-primary-dark transition"
                  >
                    <svg className="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 24 24" aria-hidden>
                      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                    </svg>
                    Booking tanggal ini via WhatsApp
                  </a>
                ) : (
                  <button
                    type="button"
                    onClick={() => setShowSelectDateModal(true)}
                    className="mt-8 flex items-center justify-center gap-2 w-full py-4 rounded-2xl bg-gray-300 text-gray-600 font-semibold cursor-pointer hover:bg-gray-400 transition"
                  >
                    <svg className="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 24 24" aria-hidden>
                      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                    </svg>
                    Booking via WhatsApp
                  </button>
                )}
              </div>

              {/* ================= RIGHT ================= */}
              <div className="col-span-12 xl:col-span-7 bg-white rounded-2xl border border-gray-200 shadow-sm p-6 sm:p-8">
                {selectedDate && selectedStatus && (
                  <p className="text-sm text-gray-600 mb-5">
                    Tanggal {formatDateForWA(selectedDate)}:{" "}
                    <span className="font-semibold text-primary">
                      {getStatusLabel(selectedStatus)}
                    </span>
                  </p>
                )}

                {/* Pagination bulan */}
                <div className="flex items-center justify-between mb-5 p-3 rounded-xl bg-gray-50 border border-gray-100">
                  <button
                    type="button"
                    onClick={goPrevMonth}
                    disabled={!canGoPrevMonth}
                    className="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-gray-700 bg-white border border-gray-200 shadow-sm hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-white transition-colors"
                    aria-label="Bulan sebelumnya"
                  >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden>
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                    <span className="hidden sm:inline">Bulan sebelumnya</span>
                  </button>
                  <span className="text-lg font-bold text-gray-900 capitalize px-2">
                    {currentMonthLabel}
                  </span>
                  <button
                    type="button"
                    onClick={goNextMonth}
                    disabled={!canGoNextMonth}
                    className="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-gray-700 bg-white border border-gray-200 shadow-sm hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-white transition-colors"
                    aria-label="Bulan berikutnya"
                  >
                    <span className="hidden sm:inline">Bulan berikutnya</span>
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden>
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                    </svg>
                  </button>
                </div>

                <div className="event-availability-calendar w-full min-w-0">
                  <DayPicker
                    mode="single"
                    selected={selectedDate}
                    onSelect={setSelectedDate}
                    disabled={{ before: today }}
                    month={currentMonth}
                    onMonthChange={setCurrentMonth}
                    startMonth={startMonth}
                    endMonth={endMonth}
                    hideNavigation
                    modifiers={{
                      fullyBooked: fullyBookedDates,
                      limited: limitedDates,
                    }}
                    modifiersClassNames={{
                      fullyBooked: "rdp-fully-booked",
                      limited: "rdp-limited",
                    }}
                    components={{ DayButton: DayButtonWithInfo }}
                    className="p-2 w-full"
                    classNames={{
                      root: "w-full max-w-full",
                      months: "w-full max-w-full",
                      month: "w-full max-w-full",
                      month_grid: "w-full max-w-full table-fixed",
                      month_caption: "mb-4",
                      caption_label: "sr-only",
                      weekdays: "grid grid-cols-7 bg-gray-50 w-full",
                      weekday:
                        "py-3 text-center text-xs font-semibold text-gray-500",
                      week: "grid grid-cols-7 w-full",
                      day: "border border-gray-100 p-1",
                      day_button:
                        "w-full min-h-[4.5rem] flex flex-col items-center justify-center rounded-xl hover:bg-primary/10 transition",
                      selected: "!bg-primary !text-white",
                      today: "ring-2 ring-primary/40 ring-inset",
                    }}
                  />
                </div>
              </div>
            </div>
          </div>
        </section>

        <p className="text-center text-sm text-gray-500 pb-8">
          <Link to="/" className="text-primary hover:underline font-medium">
            ← Kembali ke beranda
          </Link>
        </p>

        {/* Modal: pilih tanggal dulu */}
        {showSelectDateModal && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
            onClick={() => setShowSelectDateModal(false)}
            role="presentation"
          >
            <div
              className="relative w-full max-w-sm rounded-2xl bg-white shadow-xl p-6 text-center"
              onClick={(e) => e.stopPropagation()}
              role="dialog"
              aria-modal="true"
              aria-labelledby="select-date-modal-title"
              aria-describedby="select-date-modal-desc"
            >
              <div className="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-amber-100">
                <svg
                  className="size-7 text-amber-600"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                  />
                </svg>
              </div>
              <h3
                id="select-date-modal-title"
                className="text-xl font-bold text-gray-900 mb-2"
              >
                Pilih tanggal terlebih dahulu
              </h3>
              <p
                id="select-date-modal-desc"
                className="text-gray-600 text-sm mb-6"
              >
                Silakan pilih tanggal di kalender untuk melanjutkan booking via WhatsApp.
              </p>
              <button
                type="button"
                onClick={() => setShowSelectDateModal(false)}
                className="w-full py-3 px-4 rounded-xl bg-primary text-white font-semibold hover:bg-primary-dark transition-colors"
              >
                Mengerti
              </button>
            </div>
          </div>
        )}
      </main>
    </div>
  );
}
