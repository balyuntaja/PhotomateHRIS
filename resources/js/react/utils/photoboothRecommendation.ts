export interface RecommendationInput {
  days: number;
  guestsPerDay: number;
  peoplePerSession: number;
  sessionDuration: number;
}

export interface PackageOption {
  devices: number;
  hours: number;
  minutesPerDevice: number;
  pricePerDay: number;
  totalPrice: number;
}

export interface CombinationResult {
  devices: number;
  hours: number;
  availableMinutes: number;
  sessionCapacity: number;
  peopleCapacity: number;
  status: "Kurang" | "Cocok" | "Direkomendasikan" | "Sangat Cukup" | "Berlebih";
  statusColorClass: string;
  pricePerDay: number;
  totalPrice: number;
}

export interface RecommendationResult {
  requiredSessions: number;
  requiredMinutes: number;
  recommended: PackageOption | null;
  alternative: PackageOption | null;
  combinations: CombinationResult[];
}

// Pricing mapping from pricing.ts: Silver (2 Jam: 1.599.000), Gold (3 Jam: 2.199.000), Platinum (4 Jam: 2.699.000)
const PACKAGE_BASE_PRICES: Record<number, number> = {
  2: 1599000,
  3: 2199000,
  4: 2699000,
};

function calculatePrice(devices: number, hours: number, days: number) {
  const basePrice = PACKAGE_BASE_PRICES[hours] || 0;
  const pricePerDay = basePrice * devices;
  const totalPrice = pricePerDay * days;
  return { pricePerDay, totalPrice };
}

export function calculatePackageRecommendation(
  input: RecommendationInput
): RecommendationResult {
  const { days, guestsPerDay, peoplePerSession, sessionDuration } = input;

  // 1. Calculate required sessions & minutes per day
  const requiredSessions = Math.ceil(guestsPerDay / peoplePerSession);
  const requiredMinutes = requiredSessions * sessionDuration;

  // 2. Find best options for 1 Device and 2 Devices
  // Standard packages hours: 2, 3, 4
  const packageHours = [2, 3, 4];
  
  let option1Device: PackageOption | null = null;
  for (const h of packageHours) {
    const limit = h * 60;
    if (requiredMinutes <= limit) {
      const { pricePerDay, totalPrice } = calculatePrice(1, h, days);
      option1Device = {
        devices: 1,
        hours: h,
        minutesPerDevice: requiredMinutes,
        pricePerDay,
        totalPrice,
      };
      break; // Pick the smallest hour that fits
    }
  }

  let option2Device: PackageOption | null = null;
  const minutesPerDevice2 = requiredMinutes / 2;
  for (const h of packageHours) {
    const limit = h * 60;
    if (minutesPerDevice2 <= limit) {
      const { pricePerDay, totalPrice } = calculatePrice(2, h, days);
      option2Device = {
        devices: 2,
        hours: h,
        minutesPerDevice: Math.ceil(minutesPerDevice2),
        pricePerDay,
        totalPrice,
      };
      break; // Pick the smallest hour that fits
    }
  }

  // 3. Determine recommended and alternative package
  let recommended: PackageOption | null = null;
  let alternative: PackageOption | null = null;

  if (option1Device && option2Device) {
    // If 2 devices significantly reduces the event duration (e.g., 2 hours with 2 devices vs 3 or 4 hours with 1 device)
    if (option2Device.hours < option1Device.hours) {
      recommended = option2Device;
      alternative = option1Device;
    } else {
      // If duration is the same, 1 device is more efficient / recommended
      recommended = option1Device;
      alternative = option2Device;
    }
  } else if (option2Device) {
    // Only 2 devices can handle it
    recommended = option2Device;
    alternative = null;
  } else if (option1Device) {
    // Theoretically if only 1 device can handle it (shouldn't happen since 2 devices capacity is always larger, but for safety)
    recommended = option1Device;
    alternative = null;
  }

  // 4. Evaluate all 6 combinations
  const combinations: CombinationResult[] = [];
  const devicesList = [1, 2];
  const hoursList = [2, 3, 4];

  for (const devices of devicesList) {
    for (const hours of hoursList) {
      const packageMinutes = hours * 60;
      const availableMinutes = packageMinutes * devices;
      
      const sessionCapacity = Math.floor(availableMinutes / sessionDuration);
      const peopleCapacity = sessionCapacity * peoplePerSession;

      // Determine suitability status
      let status: CombinationResult["status"] = "Kurang";
      let statusColorClass = "bg-red-50 text-red-700 border-red-200";

      const isRecommended = recommended && recommended.devices === devices && recommended.hours === hours;
      const isAlternative = alternative && alternative.devices === devices && alternative.hours === hours;

      if (devices === 1 && requiredMinutes > packageMinutes) {
        status = "Kurang";
        statusColorClass = "bg-red-50 text-red-700 border-red-200";
      } else if (devices === 2 && minutesPerDevice2 > packageMinutes) {
        status = "Kurang";
        statusColorClass = "bg-red-50 text-red-700 border-red-200";
      } else if (isRecommended) {
        status = "Direkomendasikan";
        statusColorClass = "bg-emerald-50 text-emerald-700 border-emerald-200";
      } else if (isAlternative) {
        status = "Cocok";
        statusColorClass = "bg-blue-50 text-blue-700 border-blue-200";
      } else {
        // Fits but not recommended/alternative
        const ratio = availableMinutes / requiredMinutes;
        if (ratio <= 1.5) {
          status = "Cocok";
          statusColorClass = "bg-blue-50 text-blue-700 border-blue-200";
        } else if (ratio <= 2.5) {
          status = "Sangat Cukup";
          statusColorClass = "bg-amber-50 text-amber-700 border-amber-200";
        } else {
          status = "Berlebih";
          statusColorClass = "bg-purple-50 text-purple-700 border-purple-200";
        }
      }

      const { pricePerDay, totalPrice } = calculatePrice(devices, hours, days);

      combinations.push({
        devices,
        hours,
        availableMinutes,
        sessionCapacity,
        peopleCapacity,
        status,
        statusColorClass,
        pricePerDay,
        totalPrice,
      });
    }
  }

  return {
    requiredSessions,
    requiredMinutes,
    recommended,
    alternative,
    combinations,
  };
}
