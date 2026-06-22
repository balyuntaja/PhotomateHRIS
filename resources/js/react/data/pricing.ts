export interface PricingPlan {
  name: string;
  duration: string;
  photo4R: string;
  designTemplate: string;
  features: {
    backgroundStandard: boolean;
    propsUnique: boolean;
    crewProfessional: boolean;
    filesViaLink: boolean;
    paperFrame: boolean;
  };
  nettPrice: string;
  originalPrice: string;
  isPopular?: boolean;
}

export const pricingPlans: PricingPlan[] = [
  {
    name: "Silver",
    duration: "2 Jam",
    photo4R: "UNLIMITED",
    designTemplate: "Request Design Sesuai Tema Acara",
    features: {
      backgroundStandard: true,
      propsUnique: true,
      crewProfessional: true,
      filesViaLink: true,
      paperFrame: false,
    },
    nettPrice: "1.599.000",
    originalPrice: "1.800.000",
  },
  {
    name: "Gold",
    duration: "3 Jam",
    photo4R: "UNLIMITED",
    designTemplate: "Request Design Sesuai Tema Acara",
    features: {
      backgroundStandard: true,
      propsUnique: true,
      crewProfessional: true,
      filesViaLink: true,
      paperFrame: true,
    },
    nettPrice: "2.199.000",
    originalPrice: "2.600.000",
    isPopular: true,
  },
  {
    name: "Platinum",
    duration: "4 Jam",
    photo4R: "UNLIMITED",
    designTemplate: "Request Design Sesuai Tema Acara",
    features: {
      backgroundStandard: true,
      propsUnique: true,
      crewProfessional: true,
      filesViaLink: true,
      paperFrame: true,
    },
    nettPrice: "2.699.000",
    originalPrice: "2.700.000",
  },
];

export const selfRunRows = [
  { duration: "2 Jam", price: "Rp700.000", profit: "Up to Rp1.000.000" },
  { duration: "3 Jam", price: "Rp1.000.000", profit: "Up to Rp1.250.000" },
  { duration: "4 Jam", price: "Rp1.250.000", profit: "Up to Rp1.800.000" },
  { duration: "5 Jam", price: "Rp1.500.000", profit: "Up to Rp2.400.000" },
  { duration: "6 Jam", price: "Rp1.800.000", profit: "Up to Rp2.800.000" },
];

export const sharingOneDevice = [
  {
    schema: "A",
    sessions: "51 – 100",
    split: "Photomate 85% · Panitia 15%",
    price: "Rp25.000",
  },
  {
    schema: "B",
    sessions: "100 – tak hingga",
    split: "Photomate 80% · Panitia 20%",
    price: "Rp25.000",
  },
];

export const sharingTwoDevice = [
  {
    schema: "A",
    sessions: "0 – 100",
    split: "Photomate 100% · Panitia 0%",
    price: "Rp20.000 – Rp25.000",
  },
  {
    schema: "B",
    sessions: "101 – 150",
    split: "Photomate 90% · Panitia 10%",
    price: "Rp20.000 – Rp25.000",
  },
  {
    schema: "C",
    sessions: "151 – tak hingga",
    split: "Photomate 80% · Panitia 20%",
    price: "Rp20.000 – Rp25.000",
  },
];

