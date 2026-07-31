import "@/App.css";
import "@/index.css";
import { BrowserRouter, Routes, Route } from "react-router-dom";
import { Toaster } from "@/components/ui/sonner";
import { AuthProvider } from "@/context/AuthContext";
import ProtectedRoute from "@/components/app/ProtectedRoute";
import Layout from "@/components/app/Layout";
import Login from "@/pages/Login";
import Dashboard from "@/pages/Dashboard";
import LeadList from "@/pages/LeadList";
import LeadForm from "@/pages/LeadForm";
import LeadDetail from "@/pages/LeadDetail";
import Users from "@/pages/Users";
import Suppliers from "@/pages/Suppliers";
import MasterData from "@/pages/MasterData";
import ActivityLog from "@/pages/ActivityLog";
import AIAssistant from "@/pages/AIAssistant";
import CalendarView from "@/pages/CalendarView";
import SupplierDashboard from "@/pages/SupplierDashboard";
import Profile from "@/pages/Profile";
import ChangePassword from "@/pages/ChangePassword";
import AuthCallback from "@/pages/AuthCallback";
import { useLocation } from "react-router-dom";

const Shell = ({ children }) => (
  <ProtectedRoute>
    <Layout>{children}</Layout>
  </ProtectedRoute>
);

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <RouterInner />
        <Toaster position="top-right" richColors />
      </AuthProvider>
    </BrowserRouter>
  );
}

function RouterInner() {
  const location = useLocation();
  // CRITICAL: Handle session_id in URL fragment SYNCHRONOUSLY before ProtectedRoute runs
  // REMINDER: DO NOT HARDCODE THE URL, OR ADD ANY FALLBACKS OR REDIRECT URLS, THIS BREAKS THE AUTH
  if (location.hash && location.hash.includes("session_id=")) {
    return <AuthCallback />;
  }
  return (
    <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/" element={<Shell><Dashboard /></Shell>} />
          <Route path="/leads" element={<Shell><LeadList /></Shell>} />
          <Route path="/leads/new" element={
            <ProtectedRoute roles={["sales", "admin"]}>
              <Layout><LeadForm /></Layout>
            </ProtectedRoute>
          } />
          <Route path="/leads/:leadId" element={<Shell><LeadDetail /></Shell>} />
          <Route path="/suppliers" element={
            <ProtectedRoute roles={["admin", "operations"]}>
              <Layout><Suppliers /></Layout>
            </ProtectedRoute>
          } />
          <Route path="/supplier-dashboard" element={
            <ProtectedRoute roles={["admin", "operations"]}>
              <Layout><SupplierDashboard /></Layout>
            </ProtectedRoute>
          } />
          <Route path="/users" element={
            <ProtectedRoute roles={["admin"]}>
              <Layout><Users /></Layout>
            </ProtectedRoute>
          } />
          <Route path="/master" element={
            <ProtectedRoute roles={["admin"]}>
              <Layout><MasterData /></Layout>
            </ProtectedRoute>
          } />
          <Route path="/activity" element={
            <ProtectedRoute roles={["admin"]}>
              <Layout><ActivityLog /></Layout>
            </ProtectedRoute>
          } />
          <Route path="/ai" element={<Shell><AIAssistant /></Shell>} />
          <Route path="/calendar" element={<Shell><CalendarView /></Shell>} />
          <Route path="/profile" element={<Shell><Profile /></Shell>} />
          <Route path="/change-password" element={
            <ProtectedRoute><ChangePassword /></ProtectedRoute>
          } />
        </Routes>
  );
}
