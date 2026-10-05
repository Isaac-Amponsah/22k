import { Navigate, Route, Routes } from "react-router-dom";
import { ClientsPage } from "./clients/ClientsPage";
import { ClientWorkspace } from "./clients/ClientWorkspace";
import { EmployeesPage } from "./employees/EmployeesPage";
import { PayrollRunPage } from "./payroll/PayrollRunPage";
import { PayrollRunsPage } from "./payroll/PayrollRunsPage";
import { PayslipsPage } from "./payroll/PayslipsPage";
import { PayrollRatesPage } from "./rates/PayrollRatesPage";
import { SalariesPage } from "./salaries/SalariesPage";
import { SalaryImportPage } from "./salaries/SalaryImportPage";
import { AccountantShell } from "./session/AccountantShell";
import { useSession } from "./session/SessionProvider";
import { SignInPage } from "./session/SignInPage";
import { Button, LoadingState, Notice } from "./shared/ui";

// The client whose books are open is always the :clientId in the address. Nothing else remembers it,
// so each browser tab works on the client its own address names.
export function App() {
  const { signedInUser, isLoadingSession, sessionLoadError, retryLoadingSession } = useSession();

  if (isLoadingSession) {
    return <LoadingState />;
  }
  if (sessionLoadError) {
    return (
      <main className="mx-auto grid min-h-screen max-w-md content-center gap-4 px-4">
        <Notice tone="refusal">{sessionLoadError}</Notice>
        <Button variant="primary" onClick={retryLoadingSession}>
          Try again
        </Button>
      </main>
    );
  }
  if (signedInUser === null) {
    return <SignInPage />;
  }

  return (
    <Routes>
      {/* Payslips print on their own sheet, without the app around them. */}
      <Route path="/clients/:clientId/payroll/:payrollRunId/payslips" element={<PayslipsPage />} />

      <Route element={<AccountantShell />}>
        <Route path="/" element={<ClientsPage />} />
        <Route path="/payroll-rates" element={<PayrollRatesPage />} />
        <Route path="/clients/:clientId" element={<ClientWorkspace />}>
          <Route index element={<Navigate to="payroll" replace />} />
          <Route path="payroll" element={<PayrollRunsPage />} />
          <Route path="payroll/:payrollRunId" element={<PayrollRunPage />} />
          <Route path="salaries" element={<SalariesPage />} />
          <Route path="salaries/import" element={<SalaryImportPage />} />
          <Route path="employees" element={<EmployeesPage />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  );
}
