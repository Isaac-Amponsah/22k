import { Link, NavLink, Outlet } from "react-router-dom";
import { ClientJumpSearch } from "../clients/ClientJumpSearch";
import { errorMessage } from "../lib/apiClient";
import { fullName } from "../lib/formatting";
import { Icon } from "../shared/icons";
import type { IconName } from "../shared/icons";
import { useSession } from "./SessionProvider";

const MAIN_SECTIONS: { path: string; label: string; icon: IconName; matchesExactly: boolean }[] = [
  { path: "/", label: "Dashboard", icon: "dashboard", matchesExactly: true },
  { path: "/clients", label: "Clients", icon: "clients", matchesExactly: false },
  { path: "/payroll-rates", label: "Payroll rates", icon: "rates", matchesExactly: false },
];

const SIDEBAR_LINK_CLASSES = ({ isActive }: { isActive: boolean }) =>
  `relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium ${
    isActive
      ? "bg-white/10 text-white before:absolute before:inset-y-2 before:-left-3 before:w-1 before:rounded-r before:bg-brand-glow"
      : "text-white/65 hover:bg-white/5 hover:text-white"
  }`;

const TOP_BAR_LINK_CLASSES = ({ isActive }: { isActive: boolean }) =>
  `rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap ${
    isActive ? "bg-brand-tint text-brand-deep" : "text-ink-soft hover:text-ink"
  }`;

function initialsOf(person: { first_name: string; last_name: string }): string {
  return `${person.first_name.charAt(0)}${person.last_name.charAt(0)}`.toUpperCase();
}

/**
 * The frame around every signed-in screen: a sidebar of sections and a top bar with the client
 * search and who is signed in. On a narrow screen the sidebar folds into the top bar.
 */
export function AccountantShell() {
  const { appName, signedInUser, signOut } = useSession();

  async function handleSignOut() {
    try {
      await signOut();
    } catch (thrown) {
      console.error("Sign-out failed:", errorMessage(thrown));
    }
  }

  const todayText = new Date().toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long", year: "numeric" });

  return (
    <div className="min-h-screen lg:pl-64 print:pl-0">
      <aside className="accountant-sidebar screen-only hidden bg-sidebar text-white lg:fixed lg:inset-y-0 lg:left-0 lg:flex lg:w-64 lg:flex-col">
        <Link to="/" className="mx-6 mt-6 mb-8 flex items-center gap-3 rounded-sm">
          <span className="flex size-9 items-center justify-center rounded-lg bg-brand font-serif text-lg font-semibold">
            {appName.charAt(0).toUpperCase()}
          </span>
          <span className="font-serif text-lg leading-tight font-semibold">{appName}</span>
        </Link>

        <nav className="flex flex-col gap-1 px-3" aria-label="Main">
          {MAIN_SECTIONS.map((section) => (
            <NavLink key={section.path} to={section.path} end={section.matchesExactly} className={SIDEBAR_LINK_CLASSES}>
              <Icon name={section.icon} />
              {section.label}
            </NavLink>
          ))}
        </nav>
      </aside>

      <header className="screen-only sticky top-0 z-20 border-b border-rule bg-surface">
        <div className="flex items-center gap-4 px-4 py-3 lg:px-8">
          <Link to="/" className="shrink-0 font-serif text-lg font-semibold text-ink lg:hidden">
            {appName}
          </Link>
          <ClientJumpSearch />
          <div className="ml-auto flex shrink-0 items-center gap-3">
            <span className="hidden text-sm text-ink-soft xl:block">{todayText}</span>
            {signedInUser ? (
              <span className="flex items-center gap-2.5 xl:border-l xl:border-rule xl:pl-4">
                <span
                  aria-hidden="true"
                  className="flex size-9 items-center justify-center rounded-full bg-brand-tint text-sm font-semibold text-brand-deep"
                >
                  {initialsOf(signedInUser)}
                </span>
                <span className="hidden text-sm font-medium text-ink md:block">{fullName(signedInUser)}</span>
              </span>
            ) : null}
            <button
              type="button"
              onClick={handleSignOut}
              className="flex items-center gap-2 rounded-lg border border-rule px-3 py-2 text-sm font-medium text-ink hover:bg-paper"
            >
              <Icon name="sign-out" className="size-4" />
              <span className="sr-only sm:not-sr-only">Sign out</span>
            </button>
          </div>
        </div>

        <nav className="flex gap-1 overflow-x-auto px-4 pb-2 lg:hidden" aria-label="Main">
          {MAIN_SECTIONS.map((section) => (
            <NavLink key={section.path} to={section.path} end={section.matchesExactly} className={TOP_BAR_LINK_CLASSES}>
              {section.label}
            </NavLink>
          ))}
        </nav>
      </header>

      <Outlet />
    </div>
  );
}
