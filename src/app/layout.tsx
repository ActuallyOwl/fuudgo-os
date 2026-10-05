import type { Metadata } from "next";
import "./globals.css";
import { AppProvider } from "@/components/app-provider";
import { Header,Footer,CartDock } from "@/components/ui";
export const metadata:Metadata={title:"FuudGo — Kuching food, your way",description:"Discover Kuching food favourites, explore local restaurants and order in a few easy taps."};
export default function RootLayout({children}:{children:React.ReactNode}){return <html lang="en"><body><AppProvider><Header/><main>{children}</main><Footer/><CartDock/></AppProvider></body></html>}
