"use client";
import { FormEvent, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { useApp } from "@/components/app-provider";

export default function LoginPage(){
  const router=useRouter();const {refreshUser}=useApp();const [error,setError]=useState(""),[busy,setBusy]=useState(false);
  async function submit(e:FormEvent<HTMLFormElement>){e.preventDefault();setError("");setBusy(true);const form=new FormData(e.currentTarget);try{await api("/api/login",{method:"POST",body:JSON.stringify({email:form.get("email"),password:form.get("password")})});await refreshUser();const next=new URLSearchParams(window.location.search).get("returnTo")||"/account";router.replace(next.startsWith("/")&&!next.startsWith("//")?next:"/account")}catch(err){setError(err instanceof ApiError?err.message:"We couldn't sign you in. Try again.")}finally{setBusy(false)}}
  return <div className="wrap all-restaurants"><div className="saved-empty auth-card"><span className="eyebrow">WELCOME BACK</span><h1>Good to see you.</h1><p>Sign in to keep your addresses and orders together.</p><form className="auth-form" onSubmit={submit}><label className="form-field">Email address<input name="email" type="email" autoComplete="email" required/></label><label className="form-field">Password<input name="password" type="password" autoComplete="current-password" required/></label>{error&&<p role="alert" className="error-text">{error}</p>}<button className="button primary" disabled={busy}>{busy?"Signing in…":"Sign in"}</button></form><div className="auth-links"><Link href="/forgot-password">Forgot password?</Link><span>New to FuudGo? <Link href="/register">Create an account</Link></span></div></div></div>
}
