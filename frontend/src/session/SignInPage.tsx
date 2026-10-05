// Sign-in. The picture is the product's one idea: a shelf of client binders, each its own colour,
// one pulled forward — every client's books kept apart, one open at a time. The binder colours are
// the same ones each client wears inside the app (lib/clientBinder.ts).

import { useState } from "react";
import type { FormEvent } from "react";
import { errorMessage } from "../lib/apiClient";
import { allClientBinders } from "../lib/clientBinder";
import { Button, INPUT_CLASSES, Notice } from "../shared/ui";
import { useSession } from "./SessionProvider";

/** How each binder stands on the shelf: its height as a share of the shelf, and its thickness. */
const BINDER_SHAPES = [
  { heightPercent: 84, widthRem: 2.75 },
  { heightPercent: 100, widthRem: 3.5 },
  { heightPercent: 91, widthRem: 3 },
  { heightPercent: 76, widthRem: 2.5 },
  { heightPercent: 96, widthRem: 3.75 },
  { heightPercent: 82, widthRem: 2.75 },
  { heightPercent: 100, widthRem: 3.25 },
  { heightPercent: 88, widthRem: 2.75 },
];

/** The binder drawn pulled forward off the shelf: the one client whose books are open. */
const OPEN_BINDER_INDEX = 4;

export function SignInPage() {
  const { appName, signIn } = useSession();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [isPasswordShown, setIsPasswordShown] = useState(false);
  const [refusal, setRefusal] = useState("");
  const [isSigningIn, setIsSigningIn] = useState(false);

  async function handleSubmit(submitEvent: FormEvent) {
    submitEvent.preventDefault();
    setRefusal("");
    setIsSigningIn(true);
    try {
      await signIn(email, password);
    } catch (thrown) {
      setRefusal(errorMessage(thrown));
    } finally {
      setIsSigningIn(false);
    }
  }

  return (
    <main className="grid min-h-screen bg-surface lg:grid-cols-[minmax(0,1.25fr)_minmax(24rem,1fr)]">
      <section className="flex flex-col justify-between gap-8 bg-paper px-6 pt-8 sm:px-10 lg:px-14 lg:pt-14">
        <div>
          <p className="font-serif text-xl font-semibold text-brand">{appName}</p>
          <h1 className="mt-6 max-w-xl font-serif text-3xl leading-[1.15] font-semibold text-ink sm:text-4xl lg:mt-16 lg:text-5xl">
            Every Client is specially treated.
          </h1>
        </div>
        <BinderShelf />
      </section>

      <section className="flex items-center px-6 py-10 sm:px-10 lg:px-14">
        <div className="mx-auto w-full max-w-sm">
          <h2 className="mb-8 font-serif text-2xl font-semibold text-ink">Sign in</h2>

          <form onSubmit={handleSubmit} className="space-y-5">
            {refusal ? <Notice tone="refusal">{refusal}</Notice> : null}

            <div>
              <label htmlFor="sign-in-email" className="mb-1.5 block text-sm font-medium text-ink">
                Email
              </label>
              <input
                id="sign-in-email"
                name="email"
                type="email"
                autoComplete="username"
                required
                autoFocus
                className={`${INPUT_CLASSES} py-2.5`}
                value={email}
                onChange={(changeEvent) => setEmail(changeEvent.target.value)}
              />
            </div>

            <div>
              <div className="mb-1.5 flex items-baseline justify-between">
                <label htmlFor="sign-in-password" className="text-sm font-medium text-ink">
                  Password
                </label>
                <button
                  type="button"
                  aria-pressed={isPasswordShown}
                  className="rounded text-sm font-medium text-brand underline-offset-2 hover:underline"
                  onClick={() => setIsPasswordShown((isShown) => !isShown)}
                >
                  {isPasswordShown ? "Hide password" : "Show password"}
                </button>
              </div>
              <input
                id="sign-in-password"
                name="password"
                type={isPasswordShown ? "text" : "password"}
                autoComplete="current-password"
                required
                className={`${INPUT_CLASSES} py-2.5`}
                value={password}
                onChange={(changeEvent) => setPassword(changeEvent.target.value)}
              />
            </div>

            <Button type="submit" variant="primary" className="w-full py-2.5 text-base" disabled={isSigningIn}>
              {isSigningIn ? "Signing in…" : "Sign in"}
            </Button>
          </form>
        </div>
      </section>
    </main>
  );
}

/** Decoration only: a row of binders standing on a shelf, one pulled forward. */
function BinderShelf() {
  const binders = allClientBinders();

  return (
    <div aria-hidden="true" className="binder-shelf">
      <div className="flex h-36 items-end gap-1.5 sm:h-48 sm:gap-2 lg:h-80">
        {BINDER_SHAPES.map((shape, binderIndex) => {
          const binder = binders[binderIndex % binders.length];
          const isOpenBinder = binderIndex === OPEN_BINDER_INDEX;
          return (
            <div
              key={binderIndex}
              className={`binder-spine relative shrink rounded-t-md ${isOpenBinder ? "binder-spine-open" : ""}`}
              style={{
                height: `${shape.heightPercent}%`,
                width: `${shape.widthRem}rem`,
                backgroundColor: binder.spine,
                animationDelay: `${binderIndex * 70}ms`,
              }}
            >
              {/* The label plate a paper binder carries on its spine. */}
              <span className="absolute inset-x-[18%] top-[14%] h-[26%] rounded-sm" style={{ backgroundColor: binder.tint }} />
              {/* The finger hole near its foot. */}
              <span className="absolute bottom-[12%] left-1/2 size-3 -translate-x-1/2 rounded-full bg-black/25 lg:size-4" />
            </div>
          );
        })}
      </div>
      <div className="h-2.5 rounded-t-sm bg-ink" />
    </div>
  );
}
