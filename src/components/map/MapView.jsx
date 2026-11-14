import { useEffect, useMemo, useState } from "react";
import { MapContainer, TileLayer, useMap, Marker } from "react-leaflet";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

// Pusat kota Banyuwangi (antara Taman Sritanjung & Taman Blambangan) — sama dengan jejak-wangi-app.
export const MAP_CENTER = [-8.2185, 114.3686];

const OSM_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
const PROVIDERS = {
  light: [
    ...(import.meta.env.VITE_MAP_TILE_URL ? [{ url: import.meta.env.VITE_MAP_TILE_URL, attr: OSM_ATTR }] : []),
    ...(import.meta.env.VITE_MAPTILER_API_KEY ? [{ url: `https://api.maptiler.com/maps/streets-v2/256/{z}/{x}/{y}.png?key=${import.meta.env.VITE_MAPTILER_API_KEY}`, attr: OSM_ATTR }] : []),
    { url: "https://tile.openstreetmap.org/{z}/{x}/{y}.png", attr: OSM_ATTR },
    { url: "https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png", attr: `${OSM_ATTR} &copy; CARTO` },
  ],
  dark: [
    { url: "https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png", attr: `${OSM_ATTR} &copy; CARTO` },
    { url: "https://tile.openstreetmap.org/{z}/{x}/{y}.png", attr: OSM_ATTR },
  ],
};

/** Tile layer yang pindah ke penyedia berikutnya bila banyak tile gagal. */
function Tiles({ dark }) {
  const list = PROVIDERS[dark ? "dark" : "light"];
  const [i, setI] = useState(0);
  const [errors, setErrors] = useState(0);
  const p = list[Math.min(i, list.length - 1)];
  return (
    <TileLayer
      key={p.url}
      url={p.url}
      attribution={p.attr}
      maxZoom={19}
      eventHandlers={{
        tileerror: () => setErrors((e) => { if (e + 1 >= 4 && i < list.length - 1) { setI(i + 1); return 0; } return e + 1; }),
      }}
    />
  );
}

export function FitTo({ points, padding = [36, 36], maxZoom = 16 }) {
  const map = useMap();
  const key = useMemo(() => JSON.stringify(points), [points]);
  useEffect(() => {
    if (!points?.length) return;
    if (points.length === 1) { map.setView(points[0], maxZoom); return; }
    map.fitBounds(L.latLngBounds(points), { padding, maxZoom });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, map]);
  return null;
}

/** Perbaiki ukuran peta saat kontainer berubah (modal/tab). */
function Resizer() {
  const map = useMap();
  useEffect(() => {
    const t = setTimeout(() => map.invalidateSize(), 150);
    const ro = new ResizeObserver(() => map.invalidateSize());
    ro.observe(map.getContainer());
    return () => { clearTimeout(t); ro.disconnect(); };
  }, [map]);
  return null;
}

export function MapView({ height = 320, center = MAP_CENTER, zoom = 14, dark = false, className = "", children, scrollWheelZoom = true }) {
  return (
    <div className={`relative overflow-hidden rounded-xl ${className}`} style={{ height }}>
      <MapContainer center={center} zoom={zoom} scrollWheelZoom={scrollWheelZoom} className="h-full w-full" style={{ background: dark ? "#10201a" : "#cfe3ee" }} attributionControl>
        <Tiles dark={dark} />
        <Resizer />
        {children}
      </MapContainer>
    </div>
  );
}

/** Pin berlabel seperti mockup: pil berwarna di atas titik koordinat. */
export function PinLabel({ position, text, color = "#2e7d32", onClick }) {
  const icon = useMemo(
    () => L.divIcon({ className: "", iconSize: [0, 0], html: `<div class="pin-label" style="background:${color}">${text.replace(/</g, "&lt;")}</div>` }),
    [text, color]
  );
  return <Marker position={position} icon={icon} eventHandlers={onClick ? { click: onClick } : undefined} />;
}
