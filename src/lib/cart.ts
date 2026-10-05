import type {CartLine} from "@/types";
export const money=(value:number)=>`RM ${value.toFixed(2)}`;
export const lineUnitPrice=(line:CartLine)=>{const base=line.dish.promoPrice??line.dish.price;return base+(line.modifiers??[]).flatMap(group=>group.options).reduce((sum,option)=>sum+option.priceAdjustment,0)};
export function totals(lines:CartLine[],mode:"Delivery"|"Pickup"){
 const subtotal=lines.reduce((sum,line)=>sum+lineUnitPrice(line)*line.quantity,0);const first=lines[0];const deliveryFee=mode==="Delivery"&&first?first.restaurantDeliveryFee??3.5:0;const threshold=first?.freeDeliveryThreshold;const waived=mode==="Delivery"&&threshold!=null&&subtotal>=threshold;const actualDeliveryFee=waived?0:deliveryFee;
 return{subtotal,deliveryFee:actualDeliveryFee,discount:0,discountLabel:"",total:subtotal+actualDeliveryFee};
}
