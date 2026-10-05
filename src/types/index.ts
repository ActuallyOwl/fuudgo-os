export type ModifierOption = { id: number; name: string; priceAdjustment: number };
export type ModifierGroup = { id: number; name: string; required: boolean; minimumSelections: number; maximumSelections: number; options: ModifierOption[] };
export type ModifierSelection = { groupId: number; groupName: string; options: ModifierOption[] };
export type Dish = { id: string; menuItemId?: number; name: string; description: string; price: number; image: string; category: string; popular?: boolean; available: boolean; promoPrice?: number; modifierGroups?: ModifierGroup[] };
export type Restaurant = { id: string; slug: string; name: string; image: string; cuisine: string[]; tags: string[]; rating: number; reviews: number; deliveryMinutes: number; pickupMinutes: number; deliveryFee: number; freeDeliveryThreshold?: number|null; minimum: number; promotion?: string; featured?: boolean; dishes: Dish[] };
export type CartLine = { key: string; restaurantId: string; restaurantName: string; dish: Dish; quantity: number; modifiers?: ModifierSelection[]; restaurantDeliveryFee?: number; freeDeliveryThreshold?: number|null };
export type Order = { id: string; lines: CartLine[]; subtotal: number; deliveryFee: number; discount: number; discountLabel: string; total: number; mode: "Delivery" | "Pickup"; name: string; phone: string; address: string; notes?: string; payment: string; createdAt: string };
