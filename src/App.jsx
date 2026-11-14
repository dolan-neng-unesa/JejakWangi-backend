import { lazy, Suspense } from "react";
import { Navigate, Route, Routes, useLocation } from "react-router-dom";
import { AppLayout } from "./components/layout/AppLayout";
import ErrorBoundary from "./components/ErrorBoundary";

// Tiap halaman dipecah jadi chunk sendiri: admin hanya mengunduh halaman yang dibuka
// (grafik Recharts, peta Leaflet, dsb. tidak ikut dimuat di halaman login/pengguna).
const LoginPage = lazy(() => import("./pages/LoginPage"));
const DashboardPage = lazy(() => import("./pages/DashboardPage"));
const MonitoringPage = lazy(() => import("./pages/MonitoringPage"));
const SmartGreenRoutePage = lazy(() => import("./pages/SmartGreenRoutePage"));
const FestivalPage = lazy(() => import("./pages/FestivalPage"));
const SensorIotPage = lazy(() => import("./pages/SensorIotPage"));
const MoodPage = lazy(() => import("./pages/MoodPage"));
const JejakWangiPage = lazy(() => import("./pages/JejakWangiPage"));
const GeofencingPage = lazy(() => import("./pages/GeofencingPage"));
const ReportsPage = lazy(() => import("./pages/ReportsPage"));
const UsersPage = lazy(() => import("./pages/UsersPage"));
const NotificationsPage = lazy(() => import("./pages/NotificationsPage"));
const SettingsPage = lazy(() => import("./pages/SettingsPage"));
<<<<<<< HEAD
const LaporKoridorPage = lazy(() => import("./pages/LaporKoridorPage"));
const SampahPanganPage = lazy(() => import("./pages/SampahPanganPage"));
const MonitoringKoridorPage = lazy(() => import("./pages/MonitoringKoridorPage"));
const CaraMenghitungPage = lazy(() => import("./pages/CaraMenghitungPage"));
=======
>>>>>>> 1ccb9e09cce22ea6fabbe45184c619d6f9f4d433

const Loading = () => (
  <div className="h-full min-h-[40vh] flex items-center justify-center text-muted text-sm" role="status">Memuat halaman…</div>
);

export default function App() {
  const { pathname } = useLocation();
  return (
    <ErrorBoundary resetKey={pathname}>
    <Suspense fallback={<Loading />}>
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<AppLayout />}>
        <Route index element={<DashboardPage />} />
        <Route path="monitoring" element={<MonitoringPage />} />
<<<<<<< HEAD
        <Route path="monitoring/koridor/:id?" element={<MonitoringKoridorPage />} />
        <Route path="sampah-pangan/*" element={<SampahPanganPage />} />
        <Route path="lapor-koridor/*" element={<LaporKoridorPage />} />
        <Route path="cara-menghitung" element={<CaraMenghitungPage />} />
=======
>>>>>>> 1ccb9e09cce22ea6fabbe45184c619d6f9f4d433
        <Route path="smart-green-route" element={<SmartGreenRoutePage />} />
        <Route path="kalender-festival" element={<FestivalPage />} />
        <Route path="sensor-iot" element={<SensorIotPage />} />
        <Route path="mood-wellbeing" element={<MoodPage />} />
        <Route path="jejak-wangi" element={<JejakWangiPage />} />
        <Route path="geofencing" element={<GeofencingPage />} />
        <Route path="laporan" element={<ReportsPage />} />
        <Route path="pengguna" element={<UsersPage />} />
        <Route path="notifikasi" element={<NotificationsPage />} />
        <Route path="pengaturan" element={<SettingsPage />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
    </Suspense>
    </ErrorBoundary>
  );
}
