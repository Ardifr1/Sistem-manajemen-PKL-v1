import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import AdminDashboard from "./pages/dashboard admin/dashboard-admin.jsx";
import GuruDashboard from "./pages/dashboard guru/dashboard-guru.jsx";
import IndustriDashboard from "./pages/dashboard industri/dashboard-industri.jsx";
import PerusahaanDashboard from "./pages/dashboard perusahaan/dashboard-perusahaan.jsx";
import SiswaDashboard from "./pages/dashboard siswa/dashboard-siswa.jsx";
import Login from "./pages/auth/login.jsx";
import LupaPassword from "./pages/auth/lupa-password.jsx";
import { authApi } from "./api/index.js";

const TOKEN_KEY = "simagang_token";

const ROLE_HOME = {
  admin: "/admin",
  teacher: "/guru",
  company: "/perusahaan",
  supervisor: "/pembimbing",
  student: "/siswa",
};

function readToken() {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

/** Pengarah otomatis: / → dashboard sesuai role. */
function RoleHome() {
  const user = authApi.currentUser();
  const token = readToken();
  if (!user || !token) {
    return <Navigate to="/login" replace />;
  }
  return <Navigate to={ROLE_HOME[user.role] || "/login"} replace />;
}

/** Rute yang butuh login — lempar ke /login kalau belum ada token. */
function ProtectedRoute({ roles, children }) {
  const user = authApi.currentUser();
  const token = readToken();
  if (!user || !token) {
    return <Navigate to="/login" replace />;
  }
  if (roles && !roles.includes(user.role)) {
    return <Navigate to={ROLE_HOME[user.role] || "/login"} replace />;
  }
  return children;
}

/** Kalau sudah login, /login dialihkan ke dashboard sesuai role. */
function GuestRoute({ children }) {
  const user = authApi.currentUser();
  const token = readToken();
  if (user && token) {
    return <Navigate to={ROLE_HOME[user.role] || "/login"} replace />;
  }
  return children;
}

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route
          path="/login"
          element={
            <GuestRoute>
              <Login />
            </GuestRoute>
          }
        />
        <Route
          path="/lupa-password"
          element={
            <GuestRoute>
              <LupaPassword />
            </GuestRoute>
          }
        />
        <Route path="/" element={<RoleHome />} />
        <Route
          path="/admin/*"
          element={
            <ProtectedRoute roles={["admin"]}>
              <AdminDashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/guru/*"
          element={
            <ProtectedRoute roles={["teacher"]}>
              <GuruDashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/perusahaan/*"
          element={
            <ProtectedRoute roles={["company"]}>
              <PerusahaanDashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/pembimbing/*"
          element={
            <ProtectedRoute roles={["supervisor"]}>
              <IndustriDashboard />
            </ProtectedRoute>
          }
        />
        <Route path="/industri/*" element={<Navigate to="/pembimbing" replace />} />
        <Route
          path="/siswa/*"
          element={
            <ProtectedRoute roles={["student"]}>
              <SiswaDashboard />
            </ProtectedRoute>
          }
        />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}

export default App;
