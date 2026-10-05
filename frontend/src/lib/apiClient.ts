// Every call to the PHP API goes through here: same-origin cookie session, CSRF token on writes,
// one error type for refusals.

interface ApiEnvelope<ResponseData> {
  success: boolean;
  message: string;
  data?: ResponseData;
  errors?: Record<string, string>;
}

export interface ApiResult<ResponseData> {
  data: ResponseData;
  message: string;
}

export class ApiError extends Error {
  readonly statusCode: number;
  readonly fieldErrors: Record<string, string>;

  constructor(statusCode: number, message: string, fieldErrors: Record<string, string> = {}) {
    super(message);
    this.statusCode = statusCode;
    this.fieldErrors = fieldErrors;
  }
}

/** Fired when the server says nobody is signed in, so the app can fall back to the sign-in page. */
export const SESSION_ENDED_EVENT = "payroll:session-ended";

const CSRF_TOKEN_EXPIRED_STATUS = 419;

let csrfToken = "";

export function setCsrfToken(token: string): void {
  csrfToken = token;
}

type HttpMethod = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

async function send<ResponseData>(
  method: HttpMethod,
  path: string,
  requestBody?: unknown,
  isRetryAfterTokenRefresh = false,
): Promise<ApiResult<ResponseData>> {
  const headers: Record<string, string> = { Accept: "application/json" };
  let body: BodyInit | undefined;

  if (requestBody instanceof FormData) {
    body = requestBody;
  } else if (requestBody !== undefined) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(requestBody);
  }
  if (method !== "GET") {
    headers["X-CSRF-Token"] = csrfToken;
  }

  let response: Response;
  try {
    response = await fetch(path, { method, headers, body, credentials: "same-origin" });
  } catch {
    throw new ApiError(0, "The server could not be reached. Check your connection and try again.");
  }

  let envelope: ApiEnvelope<ResponseData> | null = null;
  try {
    envelope = (await response.json()) as ApiEnvelope<ResponseData>;
  } catch {
    // Not JSON: a gateway error page, or the server is down.
  }

  if (response.status === CSRF_TOKEN_EXPIRED_STATUS && !isRetryAfterTokenRefresh) {
    await refreshCsrfToken();
    return send<ResponseData>(method, path, requestBody, true);
  }

  if (!response.ok || envelope === null || !envelope.success) {
    if (response.status === 401) {
      window.dispatchEvent(new Event(SESSION_ENDED_EVENT));
    }
    // A gateway answer with no JSON: the web server is up but the API behind it is not.
    const isApiDown = envelope === null && response.status >= 502 && response.status <= 504;
    const fallbackMessage = isApiDown ? "The server could not be reached. Try again in a moment." : "Something went wrong. Please try again.";
    throw new ApiError(response.status, envelope?.message || fallbackMessage, envelope?.errors ?? {});
  }

  return { data: envelope.data as ResponseData, message: envelope.message };
}

async function refreshCsrfToken(): Promise<void> {
  const session = await send<{ csrf_token: string }>("GET", "/api/auth/session", undefined, true);
  setCsrfToken(session.data.csrf_token);
}

export const api = {
  get: <ResponseData>(path: string) => send<ResponseData>("GET", path),
  post: <ResponseData>(path: string, requestBody?: unknown) => send<ResponseData>("POST", path, requestBody ?? {}),
  put: <ResponseData>(path: string, requestBody: unknown) => send<ResponseData>("PUT", path, requestBody),
  patch: <ResponseData>(path: string, requestBody: unknown) => send<ResponseData>("PATCH", path, requestBody),
  delete: <ResponseData>(path: string) => send<ResponseData>("DELETE", path, {}),
};

/** The message of anything thrown by an API call, for showing to the user. */
export function errorMessage(thrown: unknown): string {
  return thrown instanceof Error ? thrown.message : "Something went wrong. Please try again.";
}

export function fieldErrorsOf(thrown: unknown): Record<string, string> {
  return thrown instanceof ApiError ? thrown.fieldErrors : {};
}
