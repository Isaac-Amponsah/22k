// Who is signed in. Holds no client: the client whose books are open lives in the address bar.

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { api, errorMessage, SESSION_ENDED_EVENT, setCsrfToken } from "../lib/apiClient";
import type { SignedInUser } from "../types";

interface SessionAnswer {
  user: SignedInUser | null;
  csrf_token: string;
  app_name: string;
}

interface SessionContextValue {
  /** APP_NAME from the server's .env: the name the app goes by on screen and in the browser tab. */
  appName: string;
  signedInUser: SignedInUser | null;
  isLoadingSession: boolean;
  /** Why the session could not be loaded (the server is down or unreachable), or "" when it was. */
  sessionLoadError: string;
  retryLoadingSession: () => void;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
}

const SessionContext = createContext<SessionContextValue | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [signedInUser, setSignedInUser] = useState<SignedInUser | null>(null);
  const [isLoadingSession, setIsLoadingSession] = useState(true);
  const [appName, setAppName] = useState("");
  const [sessionLoadError, setSessionLoadError] = useState("");

  // Everything fetched belongs to whoever was signed in; none of it may outlive their session.
  const forgetEverythingFetched = useCallback(() => queryClient.clear(), [queryClient]);

  const loadSession = useCallback(() => {
    setIsLoadingSession(true);
    setSessionLoadError("");
    api
      .get<SessionAnswer>("/api/auth/session")
      .then((session) => {
        setCsrfToken(session.data.csrf_token);
        setSignedInUser(session.data.user);
        setAppName(session.data.app_name);
        document.title = session.data.app_name;
      })
      .catch((thrown) => {
        setSignedInUser(null);
        setSessionLoadError(errorMessage(thrown));
      })
      .finally(() => setIsLoadingSession(false));
  }, []);

  useEffect(loadSession, [loadSession]);

  useEffect(() => {
    const handleSessionEnded = () => {
      forgetEverythingFetched();
      setSignedInUser(null);
    };
    window.addEventListener(SESSION_ENDED_EVENT, handleSessionEnded);
    return () => window.removeEventListener(SESSION_ENDED_EVENT, handleSessionEnded);
  }, [forgetEverythingFetched]);

  const signIn = useCallback(
    async (email: string, password: string) => {
      const session = await api.post<{ user: SignedInUser; csrf_token: string }>("/api/auth/login", { email, password });
      forgetEverythingFetched();
      setCsrfToken(session.data.csrf_token);
      setSignedInUser(session.data.user);
    },
    [forgetEverythingFetched],
  );

  const signOut = useCallback(async () => {
    const session = await api.post<{ csrf_token: string }>("/api/auth/logout");
    setCsrfToken(session.data.csrf_token);
    forgetEverythingFetched();
    setSignedInUser(null);
  }, [forgetEverythingFetched]);

  const contextValue = useMemo(
    () => ({ appName, signedInUser, isLoadingSession, sessionLoadError, retryLoadingSession: loadSession, signIn, signOut }),
    [appName, signedInUser, isLoadingSession, sessionLoadError, loadSession, signIn, signOut],
  );

  return <SessionContext.Provider value={contextValue}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionContextValue {
  const session = useContext(SessionContext);
  if (session === null) {
    throw new Error("useSession must be used inside SessionProvider.");
  }
  return session;
}
