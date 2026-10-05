"use client";
import Link from "next/link";
import { ArrowLeft,Heart } from "lucide-react";

import { useApp } from "@/components/app-provider";
import { RestaurantCard } from "@/components/ui";
export default function SavedPage(){const {savedIds,restaurants}=useApp();const saved=restaurants.filter(r=>savedIds.includes(r.id));return <div className="wrap all-restaurants saved-page"><Link href="/" className="back-link"><ArrowLeft size={15}/> Back to exploring</Link><span className="eyebrow">YOUR LITTLE SHORTLIST</span><h1>Saved for later.</h1><p>Keep the places you love close by for your next craving.</p>{saved.length?<><div className="all-results-label"><b>{saved.length} saved {saved.length===1?"restaurant":"restaurants"}</b><span>Stored on this device</span></div><div className="restaurant-grid">{saved.map(r=><RestaurantCard key={r.id} restaurant={r}/>)}</div></>:<div className="saved-empty"><span><Heart size={22}/></span><h2>A good place to start.</h2><p>Tap the heart on any restaurant to save it here for later.</p><Link href="/restaurants" className="button primary">Explore restaurants</Link></div>}</div>}
