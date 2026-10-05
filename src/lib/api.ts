const configuredApiUrl = process.env.NEXT_PUBLIC_API_URL;
const baseUrl = (configuredApiUrl ?? (process.env.NODE_ENV === "development" ? "http://localhost:8000" : "")).replace(/\/$/, "");
const csrfUrl = baseUrl.startsWith("/") ? "/sanctum/csrf-cookie" : `${baseUrl}/sanctum/csrf-cookie`;

function endpoint(path: string) {
  if (!baseUrl.startsWith("/")) return `${baseUrl}${path}`;
  const apiPath = path.startsWith("/api/") ? path.slice("/api".length) : path;
  return `${baseUrl}${apiPath}`;
}

export type ApiUser = { id: number; name: string; email: string; phone: string | null; role: "customer" | "admin" };
export type ApiAddress = { id: number; label: string; line1: string; line2: string | null; area: string; postcode: string | null; instructions: string | null; isDefault: boolean };

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;
  constructor(message: string, status: number, errors?: Record<string, string[]>) { super(message); this.status = status; this.errors = errors; }
}

function readCookie(name: string) {
  if (typeof document === "undefined") return "";
  const prefix = `${name}=`;
  return document.cookie.split(";").map(part => part.trim()).find(part => part.startsWith(prefix))?.slice(prefix.length) || "";
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method || "GET").toUpperCase();
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  const isForm = typeof FormData !== "undefined" && init.body instanceof FormData;
  if (init.body && !isForm && !headers.has("Content-Type")) headers.set("Content-Type", "application/json");
  if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
    if (!readCookie("XSRF-TOKEN")) await fetch(csrfUrl, { credentials: "include", headers: { Accept: "application/json" } });
    const token = readCookie("XSRF-TOKEN");
    if (token) headers.set("X-XSRF-TOKEN", decodeURIComponent(token));
  }
  let response: Response;
  try { response = await fetch(endpoint(path), { ...init, method, headers, credentials: "include" }); }
  catch { throw new ApiError("We couldn't reach FuudGo. Try again.", 0); }
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const message = response.status === 401 ? "Please sign in to continue." : response.status === 403 ? "You don't have access to that page." : payload.message || "Something went wrong. Please try again.";
    throw new ApiError(message, response.status, payload.errors);
  }
  return payload as T;
}

export { baseUrl };
