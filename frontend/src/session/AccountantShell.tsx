import { NavLink, Outlet } from "react-router-dom";
import { errorMessage } from "../lib/apiClient";
import { fullName } from "../lib/formatting";
import { Button } from "../shared/ui";
import { useSession } from "./SessionProvider";

const NAV_LINK_CLASSES = ({ isActive }: { isActive: boolean }) =>
  `rounded-md px-3 py-1.5 text-sm font-medium ${isActive ? "bg-white/15 text-white" : "text-white/75 hover:text-white"}`;

/** The bar across the top of every signed-in screen. */
export function AccountantShell() {
  const { appName, signedInUser, signOut } = useSession();

  async function handleSignOut() {
    try {
      await signOut();
    } catch (thrown) {
      console.error("Sign-out failed:", errorMessage(thrown));
    }
  }

  return (
    <div className="min-h-screen">
      <header className="screen-only bg-ledger-deep text-white">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
          <span className="font-serif text-lg font-semibold">{appName}</span>
          <nav className="flex gap-1" aria-label="Main">
            <NavLink to="/" end className={NAV_LINK_CLASSES}>
              Clients
            </NavLink>
            <NavLink to="/payroll-rates" className={NAV_LINK_CLASSES}>
              Payroll rates
            </NavLink>
          </nav>
          <div className="ml-auto flex items-center gap-3">
            {signedInUser ? <span className="text-sm text-white/80">{fullName(signedInUser)}</span> : null}
            <Button onClick={handleSignOut} className="border-white/30 bg-transparent text-white hover:bg-white/10">
              Sign out
            </Button>
          </div>
        </div>
      </header>
      <Outlet />
    </div>
  );
}
