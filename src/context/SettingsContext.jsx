import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { settings as settingsApi } from "../services/jejakwangi";
import { setTimeZone } from "../lib/format";
import { useAuth } from "./AuthContext";

// Nilai bawaan — harus sama dengan AppSettingModel::DEFAULTS di backend.
export const DEFAULT_SETTINGS = {
  "app.name": "JEJAK WANGI",
  "app.region_label": "Banyuwangi Mandiri",
  "app.language": "id",
  "app.timezone": "Asia/Jakarta",
  "sensor.offline_minutes": 5,
  "sensor.retention_days": 30,
  "sensor.auto_refresh_seconds": 30,
  "geofence.default_radius": 70,
  "geofence.default_active": true,
  "notify.push": true,
  "notify.email": true,
  "notify.sms": false,
  "notify.cat.lingkungan": true,
  "notify.cat.sensor": true,
  "notify.cat.pengguna": false,
  "notify.cat.geofence": true,
  "notify.cat.festival": true,
  "notify.cat.mood": true,
  "integration.festival.enabled": true,
  "integration.sensor.enabled": true,
  "integration.geofence.enabled": true,
  "integration.email.enabled": true,
  "integration.email.recipients": "",
  "integration.email.digest": "harian",
};

const SettingsCtx = createContext(null);
export const useSettings = () => useContext(SettingsCtx);

export function SettingsProvider({ children }) {
  const { user } = useAuth();
  const [values, setValues] = useState(DEFAULT_SETTINGS);
  const [system, setSystem] = useState(null);
  const [loaded, setLoaded] = useState(false);

  const apply = useCallback((res) => {
    const merged = { ...DEFAULT_SETTINGS, ...(res?.data || {}) };
    setValues(merged);
    if (res?.system) setSystem(res.system);
    setTimeZone(merged["app.timezone"]);
    return merged;
  }, []);

  const reload = useCallback(async () => {
    try { apply(await settingsApi.get()); } catch { /* pakai default bila endpoint belum ada */ }
    setLoaded(true);
  }, [apply]);

  useEffect(() => { if (user) reload(); else setLoaded(false); }, [user, reload]);

  const save = useCallback(async (patch) => {
    const res = await settingsApi.save(patch);
    return apply({ data: res?.data ?? { ...values, ...patch }, system });
  }, [apply, values, system]);

  const v = useMemo(() => ({ values, system, loaded, reload, save, get: (k) => values[k] }), [values, system, loaded, reload, save]);
  return <SettingsCtx.Provider value={v}>{children}</SettingsCtx.Provider>;
}
