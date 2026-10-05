import { notFound } from "next/navigation";
import { restaurants } from "@/data/restaurants";
import RestaurantView from "./view";
export default async function RestaurantPage({params}:{params:Promise<{slug:string}>}){const {slug}=await params;const restaurant=restaurants.find(r=>r.slug===slug);if(!restaurant)notFound();return <RestaurantView restaurant={restaurant}/>}
